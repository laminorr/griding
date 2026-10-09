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

        // Classic: buy 98 → sell 100 → buy 98 → ... every swing closes a cycle.
        $classic = $sim->run($this->candles(), $this->segments(), '0.02', 1, 'classic');
        $this->assertSame(5, $classic['cycles']);
        $this->assertSame(1, $classic['open_exits']);
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
