<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\GridReplaySimulator;
use PHPUnit\Framework\TestCase;

final class GridReplaySimulatorTest extends TestCase
{
    /** Price oscillates 100 → 95 → 103 three times; one buy at 98, spacing 2% → exit sell at 99.96 → 100 (tick 1 rounds up). */
    private function candles(): array
    {
        $cs = [];
        $t = 0;
        foreach (range(1, 3) as $_) {
            $cs[] = ['t' => $t += 60, 'o' => '100', 'h' => '100', 'l' => '95', 'c' => '95'];
            $cs[] = ['t' => $t += 60, 'o' => '95', 'h' => '103', 'l' => '95', 'c' => '103'];
            $cs[] = ['t' => $t += 60, 'o' => '103', 'h' => '103', 'l' => '100', 'c' => '100'];
        }
        return $cs;
    }

    private function segments(): array
    {
        return [['start' => 0, 'end' => PHP_INT_MAX, 'orders' => [['side' => 'buy', 'price' => '98', 'amount' => '1']]]];
    }

    public function test_bot_rule_closes_one_cycle_classic_rearms(): void
    {
        $sim = new GridReplaySimulator();

        $bot = $sim->run($this->candles(), $this->segments(), '0.02', 1, 'bot');
        $this->assertSame(1, $bot['cycles']);
        $this->assertSame(2, $bot['fills']);
        $this->assertSame(['buy' => '98', 'sell' => '100'], array_intersect_key($bot['cycle_list'][0], ['buy' => 1, 'sell' => 1]));

        // Classic (GridRearmer rules): buy 98 → exit sell 100 → re-arm buy 98
        // (the level's fixed price) → … every down-up swing closes ONE cycle.
        // (The pre-re-arm replay also counted the drifting buy-back's fill as a
        // cycle and reported 5 here; a re-arm's fill opens a cycle, it does not
        // close one.)
        $classic = $sim->run($this->candles(), $this->segments(), '0.02', 1, 'classic');
        $this->assertSame(3, $classic['cycles']);
        $this->assertSame(0, $classic['open_exits']);
        $this->assertSame(3, $classic['rearms']);   // the third one is still waiting at 98
        foreach ($classic['cycle_list'] as $c) {
            $this->assertSame(['buy' => '98', 'sell' => '100'], array_intersect_key($c, ['buy' => 1, 'sell' => 1]));
        }
    }

    public function test_classic_rearm_sell_first_level_keeps_its_fixed_price(): void
    {
        // Sell at 102, spacing 2% → exit buy 99.96 → 99 (tick 1 rounds down).
        $cs = [];
        $t = 0;
        foreach (range(1, 4) as $_) {
            $cs[] = ['t' => $t += 60, 'o' => '100', 'h' => '103', 'l' => '100', 'c' => '103'];
            $cs[] = ['t' => $t += 60, 'o' => '103', 'h' => '103', 'l' => '98', 'c' => '98'];
        }
        $seg = [['start' => 0, 'end' => PHP_INT_MAX, 'orders' => [['side' => 'sell', 'price' => '102', 'amount' => '1']]]];

        $r = (new GridReplaySimulator())->run($cs, $seg, '0.02', 1, 'classic');

        $this->assertSame(4, $r['cycles']);
        foreach ($r['cycle_list'] as $c) {
            $this->assertSame('sell', $c['first_side']);
            $this->assertSame(['buy' => '99', 'sell' => '102'], array_intersect_key($c, ['buy' => 1, 'sell' => 1]));
        }
        $this->assertSame(1, (new GridReplaySimulator())->run($cs, $seg, '0.02', 1, 'bot')['cycles']);
    }

    public function test_classic_rearm_skips_below_minimum(): void
    {
        $cs = [
            ['t' => 60, 'o' => '99', 'h' => '99', 'l' => '97', 'c' => '97'],    // buy 98 fills → exit sell 100
            ['t' => 120, 'o' => '97', 'h' => '101', 'l' => '97', 'c' => '101'], // exit fills → re-arm buy 98
        ];
        $seg = [['start' => 0, 'end' => PHP_INT_MAX, 'orders' => [['side' => 'buy', 'price' => '98', 'amount' => '1']]]];

        $r = (new GridReplaySimulator())->run($cs, $seg, '0.02', 1, 'classic');
        $this->assertSame(1, $r['cycles']);
        $this->assertSame(1, $r['rearms']);

        // 1 × 98 is below a 1000 minimum: skipped, level dark.
        $r = (new GridReplaySimulator())->run($cs, $seg, '0.02', 1, 'classic', '1000');
        $this->assertSame(1, $r['cycles']);
        $this->assertSame(0, $r['rearms']);
        $this->assertSame(['MIN_NOTIONAL' => 1], $r['rearm_skipped']);
    }

    public function test_classic_rearm_skips_a_busy_level(): void
    {
        // Spacing 25%: grid buy 160 → exit sell 200; grid sell 200 → exit buy 150.
        $cs = [
            ['t' => 60, 'o' => '170', 'h' => '205', 'l' => '170', 'c' => '205'],  // sell 200 fills → exit buy 150
            ['t' => 120, 'o' => '205', 'h' => '205', 'l' => '155', 'c' => '155'], // buy 160 fills → exit sell 200
            ['t' => 180, 'o' => '155', 'h' => '155', 'l' => '149', 'c' => '149'], // exit buy 150 fills → re-arm sell 200?
        ];
        $seg = [['start' => 0, 'end' => PHP_INT_MAX, 'orders' => [
            ['side' => 'buy', 'price' => '160', 'amount' => '1'],
            ['side' => 'sell', 'price' => '200', 'amount' => '1'],
        ]]];

        $r = (new GridReplaySimulator())->run($cs, $seg, '0.25', 1, 'classic');

        // Level 200 already holds the live exit sell of the 160 buy → skipped.
        $this->assertSame(1, $r['cycles']);
        $this->assertSame(0, $r['rearms']);
        $this->assertSame(['LEVEL_BUSY' => 1], $r['rearm_skipped']);
    }

    public function test_classic_old_generation_exit_does_not_rearm(): void
    {
        $cs = [
            ['t' => 60, 'o' => '99', 'h' => '99', 'l' => '97', 'c' => '97'],    // gen 0: buy 98 fills → exit 100
            ['t' => 180, 'o' => '97', 'h' => '101', 'l' => '97', 'c' => '101'], // gen 1 live: exit 100 fills
            ['t' => 240, 'o' => '101', 'h' => '101', 'l' => '96', 'c' => '96'],
        ];
        $segs = [
            ['start' => 0, 'end' => 120, 'orders' => [['side' => 'buy', 'price' => '98', 'amount' => '1']]],
            ['start' => 120, 'end' => PHP_INT_MAX, 'orders' => [['side' => 'buy', 'price' => '90', 'amount' => '1']]],
        ];
        $r = (new GridReplaySimulator())->run($cs, $segs, '0.02', 1, 'classic');

        $this->assertSame(1, $r['cycles']);            // the old exit still closes its cycle
        $this->assertSame(0, $r['rearms']);            // …but level 98 stays dark
        $this->assertSame(['OLD_GENERATION' => 1], $r['rearm_skipped']);
    }

    public function test_orders_spawned_on_a_down_leg_do_not_fill_on_it(): void
    {
        // One down candle only: the buy fills, its exit (100) sits above and must stay open.
        $cs = [['t' => 60, 'o' => '101', 'h' => '101', 'l' => '96', 'c' => '96']];
        $r = (new GridReplaySimulator())->run($cs, $this->segments(), '0.02', 1, 'classic');
        $this->assertSame(0, $r['cycles']);
        $this->assertSame(1, $r['fills']);
        $this->assertSame(1, $r['open_exits']);
    }

    public function test_candles_before_a_segment_are_ignored(): void
    {
        $segments = [['start' => 10_000, 'end' => PHP_INT_MAX, 'orders' => [['side' => 'buy', 'price' => '98', 'amount' => '1']]]];
        $r = (new GridReplaySimulator())->run($this->candles(), $segments, '0.02', 1, 'classic');
        $this->assertSame(0, $r['fills']);
    }
}
