<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\MarketPrecision;
use App\Support\Money;

/**
 * Candle replay of a grid — an ESTIMATE used by `bot:audit` §9.
 *
 * Two policies on the SAME candles and the SAME starting orders:
 *   - 'bot'     : the bot's rule — a grid order's fill places ONE exit at
 *                 price × (1 ± spacing); an exit's fill places nothing (the
 *                 level is only re-armed by a later rebalance);
 *   - 'classic' : a re-arming grid — EVERY fill (exits included) places its
 *                 opposite at price × (1 ± spacing).
 * A cycle is counted when an order that was spawned by a fill (an exit)
 * itself fills.
 *
 * Path model: inside a candle the price walks O→L→H→C when C ≥ O, else
 * O→H→L→C. A buy fills when the walk reaches ≤ its price, a sell when it
 * reaches ≥ its price (fill at touch, no queue position, no partials).
 * Orders spawned on a down-leg cannot fill on that same leg (they sit on the
 * other side), which the segment walk preserves. All prices are bcmath strings.
 */
final class GridReplaySimulator
{
    /**
     * @param list<array{t:int,o:string,h:string,l:string,c:string}> $candles ascending
     * @param list<array{start:int, end:int, orders:list<array{side:string,price:string,amount:string}>}> $segments
     * @param string $spacing fraction, e.g. "0.015"
     * @return array{cycles:int, fills:int, cycle_list:list<array{t:int, first_side:string, buy:string, sell:string, amount:string}>, open_exits:int}
     */
    public function run(array $candles, array $segments, string $spacing, int $tick, string $policy): array
    {
        $rearm   = $policy === 'classic';
        $exits   = [];   // carried across segments: spawned orders
        $cycles  = [];
        $fills   = 0;

        foreach ($segments as $seg) {
            $grid = [];
            foreach ($seg['orders'] as $o) {
                $grid[] = ['side' => $o['side'], 'price' => $o['price'], 'amount' => $o['amount'], 'parent' => null];
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
                    $spawned    = [];
                    foreach ($hits as $k) {
                        $o = $book[$k];
                        $fills++;
                        if ($o['parent'] !== null) {
                            $p        = $o['parent'];
                            $cycles[] = [
                                't'          => $c['t'],
                                'first_side' => $p['side'],
                                'buy'        => $o['side'] === 'buy' ? $o['price'] : $p['price'],
                                'sell'       => $o['side'] === 'sell' ? $o['price'] : $p['price'],
                                'amount'     => $p['amount'],
                            ];
                        }
                        if ($o['parent'] === null || $rearm) {
                            $spawned[] = $this->opposite($o, $spacing, $tick);
                        }
                    }

                    $gridCount = count($grid);
                    $grid  = array_values(array_filter($grid, fn ($o, $k) => ! isset($filledKeys[$k]), ARRAY_FILTER_USE_BOTH));
                    $exits = array_values(array_filter($exits, fn ($o, $k) => ! isset($filledKeys[$k + $gridCount]), ARRAY_FILTER_USE_BOTH));
                    foreach ($spawned as $s) {
                        $exits[] = $s;
                    }
                }
            }
            // Unfilled grid orders of a segment are replaced by the next build;
            // spawned exits stay live (the bot never cancels exits on rebalance).
        }

        return ['cycles' => count($cycles), 'fills' => $fills, 'cycle_list' => $cycles, 'open_exits' => count($exits)];
    }

    /** @param array{side:string,price:string,amount:string,parent:?array} $o */
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
        ];
    }
}
