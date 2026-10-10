<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\MarketPrecision;
use App\Support\Money;

/**
 * Candle replay of a grid — an ESTIMATE used by `bot:audit` §9.
 *
 * Two policies on the SAME candles and the SAME starting orders:
 *   - 'bot'     : the bot's rule with rearm_exits OFF — a grid order's fill
 *                 places ONE exit at price × (1 ± spacing); an exit's fill
 *                 places nothing (the level is only re-armed by a rebuild);
 *   - 'classic' : the bot's rule with rearm_exits ON (App\Services\GridRearmer),
 *                 same rules as the implementation:
 *                   · an exit's fill re-arms its level: SAME side as the
 *                     cycle's original leg, at the chain ROOT's price (fixed
 *                     level, no drift), sized at the root's amount;
 *                   · one live order per price level — a re-arm whose level
 *                     already has a live order is skipped (level stays dark);
 *                   · a re-arm below $minNotional is skipped;
 *                   · generations: a segment (build) replaces the unfilled
 *                     grid AND re-arm orders of the previous one; exits stay
 *                     live, but an exit of an older generation never re-arms.
 * A cycle is counted when an exit fills (a re-arm's fill opens a new cycle,
 * it does not close one).
 *
 * Intentional differences from the implementation (it is an estimate):
 * re-arm sizing ignores fees (a re-arm sell = the root amount, a re-arm buy =
 * the root amount, which the exit sell's proceeds always cover before fees);
 * the kill switch / EXIT_BLOCKED gates and the 60-minute sweep window are not
 * modelled; fills are at touch with no queue position and no partials.
 *
 * Path model: inside a candle the price walks O→L→H→C when C ≥ O, else
 * O→H→L→C. A buy fills when the walk reaches ≤ its price, a sell when it
 * reaches ≥ its price. Orders spawned on a leg cannot fill on that same leg
 * (they sit on the other side), which the segment walk preserves. All prices
 * are bcmath strings.
 */
final class GridReplaySimulator
{
    /**
     * @param list<array{t:int,o:string,h:string,l:string,c:string}> $candles ascending
     * @param list<array{start:int, end:int, orders:list<array{side:string,price:string,amount:string}>}> $segments
     * @param string $spacing fraction, e.g. "0.015"
     * @param string $minNotional minimum order value (classic re-arms only), '0' = none
     * @return array{cycles:int, fills:int, cycle_list:list<array{t:int, first_side:string, buy:string, sell:string, amount:string}>, open_exits:int, rearms:int, rearm_skipped:array<string,int>}
     */
    public function run(array $candles, array $segments, string $spacing, int $tick, string $policy, string $minNotional = '0'): array
    {
        $rearm   = $policy === 'classic';
        $exits   = [];   // carried across segments: exit orders
        $cycles  = [];
        $fills   = 0;
        $rearms  = 0;
        $skipped = [];

        foreach ($segments as $gen => $seg) {
            // Grid orders (and re-arms) of this generation; the previous
            // generation's unfilled ones are replaced by this build.
            $grid = [];
            foreach ($seg['orders'] as $o) {
                $grid[] = ['side' => $o['side'], 'price' => $o['price'], 'amount' => $o['amount'], 'parent' => null,
                    'root' => ['side' => $o['side'], 'price' => $o['price'], 'amount' => $o['amount'], 'gen' => $gen]];
            }
            $started = false;

            foreach ($candles as $c) {
                if ($c['t'] < $seg['start'] || $c['t'] >= $seg['end']) {
                    continue;
                }
                $path = Money::compare($c['c'], $c['o']) >= 0
                    ? [$c['o'], $c['l'], $c['h'], $c['c']]
                    : [$c['o'], $c['h'], $c['l'], $c['c']];

                $legs = [];
                if (! $started) {
                    // Marketable at placement: a buy at/above or a sell at/below the open.
                    $started = true;
                    $legs[]  = [$c['o'], true];
                    $legs[]  = [$c['o'], false];
                }
                for ($i = 0; $i < count($path) - 1; $i++) {
                    $legs[] = [$path[$i + 1], Money::compare($path[$i + 1], $path[$i]) <= 0];
                }

                foreach ($legs as [$b, $down]) {
                    $book = array_merge($grid, $exits);
                    $hits = [];
                    foreach ($book as $k => $o) {
                        if ($down && $o['side'] === 'buy' && Money::compare($o['price'], $b) >= 0) {
                            $hits[] = $k;
                        } elseif (! $down && $o['side'] === 'sell' && Money::compare($o['price'], $b) <= 0) {
                            $hits[] = $k;
                        }
                    }
                    if ($hits === []) {
                        continue;
                    }
                    // Walk order: down-leg fills the highest buy first, up-leg the lowest sell.
                    usort($hits, fn ($x, $y) => $down
                        ? Money::compare($book[$y]['price'], $book[$x]['price'])
                        : Money::compare($book[$x]['price'], $book[$y]['price']));

                    $filledKeys = array_flip($hits);
                    $gridCount  = count($grid);
                    $grid  = array_values(array_filter($grid, fn ($o, $k) => ! isset($filledKeys[$k]), ARRAY_FILTER_USE_BOTH));
                    $exits = array_values(array_filter($exits, fn ($o, $k) => ! isset($filledKeys[$k + $gridCount]), ARRAY_FILTER_USE_BOTH));

                    $newExits = [];
                    $newGrid  = [];
                    foreach ($hits as $k) {
                        $o = $book[$k];
                        $fills++;
                        if ($o['parent'] === null) {
                            // A grid / re-arm order: its fill gets one exit.
                            $newExits[] = $this->opposite($o, $spacing, $tick);
                            continue;
                        }

                        // An exit: the cycle closes.
                        $p        = $o['parent'];
                        $cycles[] = [
                            't'          => $c['t'],
                            'first_side' => $p['side'],
                            'buy'        => $o['side'] === 'buy' ? $o['price'] : $p['price'],
                            'sell'       => $o['side'] === 'sell' ? $o['price'] : $p['price'],
                            'amount'     => $p['amount'],
                        ];
                        if (! $rearm) {
                            continue;
                        }

                        $root   = $o['root'];
                        $reason = null;
                        if ($root['gen'] !== $gen) {
                            $reason = 'OLD_GENERATION';
                        } elseif ($minNotional !== '0' && Money::compare(Money::mul($root['amount'], $root['price']), $minNotional) < 0) {
                            $reason = 'MIN_NOTIONAL';
                        } else {
                            foreach (array_merge($grid, $exits, $newGrid, $newExits) as $live) {
                                if (Money::compare($live['price'], $root['price']) === 0) {
                                    $reason = 'LEVEL_BUSY';
                                    break;
                                }
                            }
                        }
                        if ($reason !== null) {
                            $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                            continue;
                        }
                        $rearms++;
                        $newGrid[] = ['side' => $root['side'], 'price' => $root['price'], 'amount' => $root['amount'], 'parent' => null, 'root' => $root];
                    }

                    foreach ($newGrid as $g) {
                        $grid[] = $g;
                    }
                    foreach ($newExits as $x) {
                        $exits[] = $x;
                    }
                }
            }
            // Unfilled grid / re-arm orders of a segment are replaced by the
            // next build; exits stay live (the bot never cancels exits).
        }

        ksort($skipped);

        return [
            'cycles' => count($cycles), 'fills' => $fills, 'cycle_list' => $cycles, 'open_exits' => count($exits),
            'rearms' => $rearms, 'rearm_skipped' => $skipped,
        ];
    }

    /** @param array{side:string,price:string,amount:string,parent:?array,root:array} $o */
    private function opposite(array $o, string $spacing, int $tick): array
    {
        $sell  = $o['side'] === 'buy';
        $raw   = $sell
            ? Money::mul($o['price'], Money::add('1', $spacing))
            : Money::mul($o['price'], Money::sub('1', $spacing));
        $price = (string) MarketPrecision::alignToTick($raw, (string) $tick, $sell);

        return [
            'side'   => $sell ? 'sell' : 'buy',
            'price'  => $price,
            'amount' => $o['amount'],
            'parent' => ['side' => $o['side'], 'price' => $o['price'], 'amount' => $o['amount']],
            'root'   => $o['root'],
        ];
    }
}
