<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\BotConfig;
use App\Models\CompletedTrade;
use Database\Factories\BotConfigFactory;
use Database\Factories\CompletedTradeFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Regression coverage for the double-fee-subtraction bug in BotConfig's profit
 * accounting accessors.
 *
 * ROOT CAUSE: completed_trades.profit is ALREADY net of fees —
 * CompletedTrade::createFromOrders stores (gross - total_fee) into BOTH `profit`
 * and `net_profit`. The old accessors computed SUM(profit - fee), subtracting the
 * fee a SECOND time, so they returned (gross - 2·fee) instead of (gross - fee).
 *
 * ORACLES: the money values below are chosen so profit is already net and fee is
 * non-zero. Every expected number is derived by hand here, never from the code
 * under test. Values are plain integers so no bcmath/decimal edge case is in play
 * (the sqlite NUMERIC-affinity caveat in BuildsGridSchema does not bite because
 * these magnitudes stay well inside a 64-bit integer).
 */
final class BotConfigProfitAccountingTest extends TestCase
{
    use BuildsGridSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    /**
     * The bot-46 case: one completed trade with a known net profit and a
     * non-zero fee. total_profit must equal SUM(profit) — NOT SUM(profit - fee).
     */
    public function test_total_profit_sums_net_profit_without_subtracting_fee_again(): void
    {
        $bot = BotConfigFactory::new()->create();

        // Bot-46-like numbers (rounded to integers): net profit 58,736, fee 52,122.
        // profit is ALREADY net, so the correct total is 58,736.
        // The old buggy accessor computed profit - fee = 6,614 (gross - 2·fee).
        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id,
            'profit'        => 58_736,
            'fee'           => 52_122,
            'net_profit'    => 58_736,
            'gross_profit'  => 110_858, // gross = net + fee, for coherence only
        ]);

        $fresh = BotConfig::find($bot->id);

        // Correct: the already-net profit, summed as-is.
        $this->assertSame(58_736.0, $fresh->total_profit);

        // Must NOT reproduce the old double-fee value (gross - 2·fee = 6,614).
        $this->assertNotSame(6_614.0, $fresh->total_profit);
    }

    /**
     * total_profit sums correctly across multiple trades, again never
     * re-subtracting fee.
     */
    public function test_total_profit_sums_multiple_net_trades(): void
    {
        $bot = BotConfigFactory::new()->create();

        // Two trades: net 40,000 (fee 30,000) and net 18,736 (fee 22,122).
        // Correct total = 40,000 + 18,736 = 58,736.
        // Old buggy total = (40,000-30,000) + (18,736-22,122) = 10,000 - 3,386 = 6,614.
        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id,
            'profit'        => 40_000,
            'fee'           => 30_000,
            'net_profit'    => 40_000,
            'gross_profit'  => 70_000,
        ]);
        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id,
            'profit'        => 18_736,
            'fee'           => 22_122,
            'net_profit'    => 18_736,
            'gross_profit'  => 40_858,
        ]);

        $fresh = BotConfig::find($bot->id);

        $this->assertSame(58_736.0, $fresh->total_profit);
        $this->assertNotSame(6_614.0, $fresh->total_profit);
    }

    /**
     * Site 2 regression: a genuine winner where 0 < net_profit < fee must be
     * counted as a successful trade. The old filter (profit - fee > 0) would have
     * mislabelled it as a loss; the fixed filter (profit > 0) counts it.
     */
    public function test_successful_trades_count_counts_small_net_winner_as_win(): void
    {
        $bot = BotConfigFactory::new()->create();

        // Net profit 100 (positive → a real win) but far below the 689,500 fee.
        // Old filter: 100 - 689,500 < 0 → NOT counted. New filter: 100 > 0 → counted.
        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id,
            'profit'        => 100,
            'fee'           => 689_500,
            'net_profit'    => 100,
            'gross_profit'  => 689_600,
        ]);

        $fresh = BotConfig::find($bot->id);

        $this->assertSame(1, $fresh->successful_trades_count);
        $this->assertSame(100.0, $fresh->win_rate);
    }

    /**
     * A genuine loser (net_profit < 0) is still excluded from the win count.
     */
    public function test_successful_trades_count_excludes_net_loss(): void
    {
        $bot = BotConfigFactory::new()->create();

        CompletedTradeFactory::new()->create([
            'bot_config_id' => $bot->id,
            'profit'        => -5_000,
            'fee'           => 689_500,
            'net_profit'    => -5_000,
            'gross_profit'  => 684_500,
        ]);

        $fresh = BotConfig::find($bot->id);

        $this->assertSame(0, $fresh->successful_trades_count);
    }
}
