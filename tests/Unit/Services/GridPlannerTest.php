<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\MarketData;
use App\Services\GridPlanner;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * CHARACTERIZATION test suite for {@see \App\Services\GridPlanner::plan()}.
 *
 * plan() is the pure planning core of the grid bot: given a symbol, reference
 * price, level count, step percent, mode, budget and optional
 * fixedQty / tick / presetBaseQty, it returns a plan array of per-level items
 * (side, price, quantity, notional, below_min) plus reporting aggregates. It
 * places no orders and touches no database.
 *
 * This is Step 2 of Phase 13, same discipline as Step 1
 * (tests/Unit/Support/MoneyTest.php): it records what the code does *today*.
 * Where the actual behaviour differs from the naive expectation the assertion
 * LOCKS THE ACTUAL BEHAVIOUR and a `// CHARACTERIZATION:` comment explains the
 * surprise. Nothing here is a request to change GridPlanner; several assertions
 * pin latent quirks so a future change announces itself by breaking a test.
 *
 * WHY THIS EXTENDS Tests\TestCase (not PHPUnit's TestCase directly, unlike
 * Step 1): plan() calls config() and Log::channel('trading'), so it needs the
 * Laravel application booted. It still needs NO database — the reference price
 * is always passed explicitly as $lastPrice, so MarketData is never consulted.
 * We prove that by injecting a Mockery mock with no expectations: any call to
 * it would raise, so a green suite means plan() never reached market data.
 *
 * Expected values are computed independently of GridPlanner — by hand or by
 * exact integer arithmetic in-test — never by re-calling plan() to define its
 * own oracle. The one place a second plan() call appears (the preset-reverts
 * test) compares two configurations for EQUIVALENCE, which is the pin itself,
 * not an aggregate oracle.
 *
 * A note on the price arithmetic: the geometric spacing factor is a native
 * float pow() (bcmath has no fractional pow), but the level PRICE is
 * mid × factor rounded to an integer via Money, and then floored/ceiled to the
 * tick. All the exact-value cases below use a small mid (100000) where
 * mid × factor is far under 2^53, so the float round-trip is exact and the
 * asserted integers are unambiguous. The large-magnitude case asserts only
 * tick-multiplicity and monotonicity, which survive any residual float noise.
 */
final class GridPlannerTest extends TestCase
{
    private const SYMBOL = 'BTCIRT';

    /** A stable frozen instant so plan()['ts'] (= now()->timestamp) is deterministic. */
    private const FROZEN_TS = 1_700_000_000;

    private MarketData $md;

    protected function setUp(): void
    {
        parent::setUp();

        // No market access is expected: $lastPrice is always passed, so a call
        // to any MarketData method on this bare mock would raise and fail the
        // test. This is the "NO-DATABASE / no market data" guarantee, enforced.
        $this->md = Mockery::mock(MarketData::class);

        // Deterministic reporting timestamp.
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FROZEN_TS));

        // Pin the config inputs plan() reads, so a stray local .env cannot move
        // the assertions. Individual tests override these as needed.
        config([
            'trading.min_order_value_irt'          => 3_000_000,
            'trading.ticks.BTCIRT'                 => 10,
            'trading.exchange.fee_bps'             => 35,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function planner(): GridPlanner
    {
        return new GridPlanner($this->md);
    }

    /** @return array<int,array<string,mixed>> */
    private function side(array $plan, string $side): array
    {
        return array_values(array_filter($plan['items'], fn ($it) => $it['side'] === $side));
    }

    // =====================================================================
    // Level layout and mode (invariant 1)
    // =====================================================================

    public function test_both_mode_even_levels_splits_half_buys_half_sells(): void
    {
        // levels=6 => 3 buys strictly below mid, 3 sells strictly above.
        // stepPct=1.0 spaces the levels wide enough that none collapse onto a
        // shared tick, so the counts are exactly levels/2 each.
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', tick: 10);

        $buys  = $this->side($plan, 'buy');
        $sells = $this->side($plan, 'sell');

        $this->assertCount(3, $buys, 'both mode with 6 levels must place 3 buys');
        $this->assertCount(3, $sells, 'both mode with 6 levels must place 3 sells');
        $this->assertSame(3, $plan['per_side']);
        $this->assertSame(0, $plan['collapsed_levels']);

        foreach ($buys as $b) {
            $this->assertLessThan(100_000, $b['price'], 'every buy must sit strictly below mid');
        }
        foreach ($sells as $s) {
            $this->assertGreaterThan(100_000, $s['price'], 'every sell must sit strictly above mid');
        }
    }

    public function test_both_mode_odd_levels_throws_with_exact_message(): void
    {
        // The guard is scoped to 'both' mode and names the offending value.
        // CHARACTERIZATION / cross-reference: this guard is NARROWER than
        // GridCalculatorService::validateGridInputs(), which rejects an odd
        // level count in EVERY mode (a generic Exception, Persian message). Here
        // odd levels are legal for 'buy' and 'sell' (see the two tests below);
        // only 'both' throws, because only 'both' halves the level count.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("levels must be even when mode is 'both' (got 5)");

        $this->planner()->plan(self::SYMBOL, 100_000, 5, 1.0, 'both');
    }

    public function test_buy_mode_places_only_buys_none_above_mid(): void
    {
        // 'buy' mode uses ALL levels on the buy side (per_side = levels), and an
        // ODD level count is accepted here — no even-count guard applies.
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 3, 1.0, 'buy', tick: 10);

        $this->assertSame(3, $plan['per_side']);
        $this->assertCount(3, $plan['items']);
        foreach ($plan['items'] as $it) {
            $this->assertSame('buy', $it['side']);
            $this->assertLessThan(100_000, $it['price']);
        }
    }

    public function test_sell_mode_places_only_sells_none_below_mid(): void
    {
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 3, 1.0, 'sell', tick: 10);

        $this->assertSame(3, $plan['per_side']);
        $this->assertCount(3, $plan['items']);
        foreach ($plan['items'] as $it) {
            $this->assertSame('sell', $it['side']);
            $this->assertGreaterThan(100_000, $it['price']);
        }
    }

    public function test_invalid_mode_throws(): void
    {
        // mode is lower-cased/trimmed before the guard, so the message carries
        // the normalised spelling.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode: sideways');

        $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'SIDEWAYS');
    }

    public function test_levels_below_one_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('levels must be >= 1');

        $this->planner()->plan(self::SYMBOL, 100_000, 0, 1.0, 'both');
    }

    public function test_non_positive_step_pct_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stepPct must be > 0');

        $this->planner()->plan(self::SYMBOL, 100_000, 6, 0.0, 'both');
    }

    public function test_negative_step_pct_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stepPct must be > 0');

        $this->planner()->plan(self::SYMBOL, 100_000, 6, -0.25, 'both');
    }

    public function test_non_positive_last_price_throws(): void
    {
        // Guarded independently of MarketData: an explicit non-positive price
        // still raises rather than reading the market.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Last price not available for BTCIRT');

        $this->planner()->plan(self::SYMBOL, 0, 6, 1.0, 'both');
    }

    // =====================================================================
    // Price spacing and tick alignment (invariant 2)
    // =====================================================================

    public function test_prices_are_monotonic_and_move_away_from_reference(): void
    {
        // Buys march DOWN away from mid as the level index grows; sells march UP.
        // After plan()'s sort, buys come out ascending and sells descending, so
        // the "further from mid" ordering is: buys reversed, sells as-returned.
        $plan  = $this->planner()->plan(self::SYMBOL, 100_000, 8, 1.0, 'both', tick: 10);
        $buys  = array_column($this->side($plan, 'buy'), 'price');
        $sells = array_column($this->side($plan, 'sell'), 'price');

        // buys returned ascending -> each is < the previous when read low-to-high
        $sortedBuys = $buys;
        sort($sortedBuys);
        $this->assertSame($sortedBuys, $buys, 'buys are returned in ascending price order');
        $this->assertSame(count($buys), count(array_unique($buys)), 'buy prices are distinct here');

        // sells returned descending
        $sortedSellsDesc = $sells;
        rsort($sortedSellsDesc);
        $this->assertSame($sortedSellsDesc, $sells, 'sells are returned in descending price order');
        $this->assertSame(count($sells), count(array_unique($sells)), 'sell prices are distinct here');
    }

    public function test_buys_floor_and_sells_ceil_to_the_tick(): void
    {
        // Chosen so the pre-alignment value lands STRICTLY BETWEEN two ticks, so
        // floor vs ceil is actually distinguishable:
        //   mid=100000, stepPct=0.007 => step=0.00007
        //   buy  i=1: round(100000 * 0.99993) = 99993  -> floor(tick 10) = 99990
        //   sell i=1: round(100000 * 1.00007) = 100007 -> ceil (tick 10) = 100010
        // If both directions used the same rounding they would both give 100000;
        // the different answers prove floor-down / ceil-up.
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 2, 0.007, 'both', tick: 10);

        $buy  = $this->side($plan, 'buy')[0];
        $sell = $this->side($plan, 'sell')[0];

        $this->assertSame(99_990, $buy['price'], 'buy aligns DOWN to the tick');
        $this->assertSame(100_010, $sell['price'], 'sell aligns UP to the tick');

        // Both raws (99993, 100007) were strictly between ticks, so the aligned
        // value differs from a naive round-to-nearest-tick (which would be
        // 99990 and 100010 too, here) — the point is direction, asserted above.
        $this->assertSame(0, $buy['price'] % 10);
        $this->assertSame(0, $sell['price'] % 10);
    }

    public function test_buys_floor_and_sells_ceil_at_realistic_btcirt_magnitude(): void
    {
        // The mid=100000 floor/ceil test above sits SIX orders of magnitude below a
        // real BTCIRT price. This repeats the floor-down / ceil-up characterization
        // at a realistic mid, chosen so BOTH pre-alignment (raw) prices land
        // STRICTLY BETWEEN two ticks — so direction genuinely matters:
        //   mid=98,543,211,041, stepPct=0.25 (step 0.0025), tick=10, levels=2 (1+1)
        //   buy  i=1: round(mid * 0.9975) = 98,296,853,013 -> floor(10) = 98,296,853,010
        //   sell i=1: round(mid * 1.0025) = 98,789,569,069 -> ceil (10) = 98,789,569,070
        // Both raws end in a non-zero digit (…013 / …069), so a floor and a ceil of
        // the SAME raw would differ — the buy rounds DOWN, the sell rounds UP. The
        // expected integers were derived independently from the geometric factor
        // and the tick rule (the raw fractional parts, 0.398 and 0.602, sit ~0.10
        // clear of the .5 rounding boundary, so the raws are unambiguous), never by
        // re-calling plan().
        $plan = $this->planner()->plan(self::SYMBOL, 98_543_211_041, 2, 0.25, 'both', tick: 10);

        $buy  = $this->side($plan, 'buy')[0];
        $sell = $this->side($plan, 'sell')[0];

        $this->assertSame(98_296_853_010, $buy['price'], 'buy aligns DOWN to the tick at real magnitude');
        $this->assertSame(98_789_569_070, $sell['price'], 'sell aligns UP to the tick at real magnitude');

        // Every returned price is an exact multiple of the tick.
        foreach ($plan['items'] as $it) {
            $this->assertSame(0, $it['price'] % 10, "price {$it['price']} must be a clean multiple of the tick");
        }

        // Floor vs ceil genuinely diverge: the buy landed below its raw, the sell
        // above its raw — proving the two directions produce different answers.
        $this->assertLessThan(98_296_853_013, $buy['price'], 'buy floored below its raw 98,296,853,013');
        $this->assertGreaterThan(98_789_569_069, $sell['price'], 'sell ceiled above its raw 98,789,569,069');
    }

    public function test_every_returned_price_is_an_exact_multiple_of_the_tick(): void
    {
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 0.25, 'both', tick: 10);

        foreach ($plan['items'] as $it) {
            $this->assertSame(0, $it['price'] % 10, "price {$it['price']} must be a multiple of the tick");
        }
    }

    public function test_tick_defaults_from_config_when_not_passed(): void
    {
        // No $tick argument -> plan() reads config("trading.ticks.BTCIRT").
        config(['trading.ticks.BTCIRT' => 50]);

        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 4, 0.25, 'both');

        $this->assertSame(50, $plan['tick'], 'tick defaults from config');
        foreach ($plan['items'] as $it) {
            $this->assertSame(0, $it['price'] % 50, "price {$it['price']} must be a multiple of the config tick 50");
        }
    }

    public function test_tick_alignment_absorbs_float_drift_at_a_large_level_count(): void
    {
        // 40 buy levels at a real BTCIRT magnitude (mid = 1e11). The geometric
        // factor is an accumulating float pow(); if tick alignment were not
        // absorbing the residual noise, some price would land off-tick or the
        // sequence would wobble. Assert every price is a clean multiple of the
        // tick and the sequence is strictly decreasing.
        $plan = $this->planner()->plan(self::SYMBOL, 100_000_000_000, 40, 0.25, 'buy', tick: 10);

        $prices = array_column($plan['items'], 'price');

        foreach ($prices as $p) {
            $this->assertSame(0, $p % 10, "price {$p} must be a clean multiple of the tick despite float drift");
        }

        // Returned ascending; reading them that way each must strictly exceed
        // the previous (no two collapsed here at this spacing/magnitude).
        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices);
        $this->assertSame(count($prices), count(array_unique($prices)), 'no drift-induced duplicates at 1e11 / step 0.25');
    }

    // =====================================================================
    // collapsed_levels
    // =====================================================================

    public function test_adjacent_levels_that_align_onto_one_tick_are_collapsed_and_removed(): void
    {
        // Step tiny relative to tick: mid=100000, stepPct=0.1 (step 0.001),
        // tick=1000. Three buy levels:
        //   i=1: round(100000*0.999)      = 99900 -> floor(1000) = 99000
        //   i=2: round(100000*0.998001)   = 99800 -> floor(1000) = 99000
        //   i=3: round(100000*0.997003)   = 99700 -> floor(1000) = 99000
        // All three land on the SAME tick (99000).
        //
        // CHARACTERIZATION: the duplicate items are REMOVED, not retained. The
        // dedup keys on "side:price" and drops the later collisions. So a caller
        // that asked for 3 levels receives ONE item, while per_side still
        // reports the requested 3. collapsed_levels counts the DROPPED items (2).
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 3, 0.1, 'buy', tick: 1000);

        $this->assertSame(2, $plan['collapsed_levels'], 'two of the three levels collapsed');
        $this->assertCount(1, $plan['items'], 'collapsed duplicates are dropped, not kept');
        $this->assertSame(3, $plan['per_side'], 'per_side still reports the requested level count');
        $this->assertSame(99_000, $plan['items'][0]['price']);
    }

    // =====================================================================
    // below_min flagging (invariant 3)
    // =====================================================================

    public function test_below_min_items_are_flagged_true_and_still_present(): void
    {
        // budget split: notional per item = intdiv(budget, count).
        // budget=6_000_000 over 6 levels => 1_000_000 each, which is below the
        // 3_000_000 minimum. GridPlanner FLAGS but does NOT drop.
        config(['trading.min_order_value_irt' => 3_000_000]);

        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', 6_000_000, tick: 10);

        $this->assertCount(6, $plan['items'], 'flagged items remain present — nothing is dropped');
        foreach ($plan['items'] as $it) {
            $this->assertTrue($it['below_min'], "item at {$it['price']} (notional {$it['notional']}) must be flagged");
        }
        $flagged = array_filter($plan['items'], fn ($it) => $it['below_min'] === true);
        $this->assertCount(6, $flagged);
        $this->assertSame(6, $plan['below_min_orders'], 'aggregate matches the number of flagged items');
    }

    public function test_plan_entirely_above_the_minimum_reports_zero_below_min(): void
    {
        // budget=60_000_000 over 6 levels => 10_000_000 each, well above min.
        config(['trading.min_order_value_irt' => 3_000_000]);

        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000, tick: 10);

        foreach ($plan['items'] as $it) {
            $this->assertFalse($it['below_min']);
        }
        $this->assertSame(0, $plan['below_min_orders']);
    }

    public function test_zero_notional_items_are_not_flagged_below_min(): void
    {
        // CHARACTERIZATION: with no budget, no fixedQty and no preset, every
        // item gets qty '0' and notional 0. below_min is (notional > 0 && ...),
        // so a notional of 0 is NOT flagged — even though 0 < min_order_value.
        // "below_min" means "a real order too small", not "an empty order".
        config(['trading.min_order_value_irt' => 3_000_000]);

        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', 0, tick: 10);

        foreach ($plan['items'] as $it) {
            $this->assertSame(0, $it['notional']);
            $this->assertFalse($it['below_min'], 'a zero-notional item is not flagged below_min');
        }
        $this->assertSame(0, $plan['below_min_orders']);
    }

    public function test_min_order_value_reads_from_config(): void
    {
        config(['trading.min_order_value_irt' => 5_000_000]);
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', tick: 10);

        $this->assertSame(5_000_000, $plan['min_order_value_irt']);
    }

    public function test_min_order_value_falls_back_to_three_million_when_config_empty(): void
    {
        // The hard-coded fallback exists specifically to survive a config-load
        // failure. A zero/empty config value trips empty() and engages it.
        config(['trading.min_order_value_irt' => 0]);

        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', tick: 10);

        $this->assertSame(3_000_000, $plan['min_order_value_irt'], 'fallback engages on empty config');
    }

    // =====================================================================
    // presetBaseQty (Phase 11 Step 5 — balance-aware sell sizing)
    // =====================================================================

    public function test_preset_base_qty_splits_evenly_across_sell_levels_only(): void
    {
        // presetBaseQty '0.006' over 3 sell levels => 0.002 per sell. Buys keep
        // their budget-derived sizing and are untouched by the preset.
        $plan = $this->planner()->plan(
            self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000,
            fixedQty: null, tick: 10, presetBaseQty: '0.006'
        );

        $this->assertSame('0.002', $plan['preset_sell_qty']);

        foreach ($this->side($plan, 'sell') as $s) {
            $this->assertSame('0.002', $s['quantity'], 'each sell carries the preset split');
        }
        foreach ($this->side($plan, 'buy') as $b) {
            $this->assertNotSame('0.002', $b['quantity'], 'buys are sized from budget, not the preset');
        }
    }

    public function test_preset_that_divides_to_zero_per_level_reverts_to_naive_sizing(): void
    {
        // '0.00000001' (1e-8) over 3 sells => 0.0000000033..., which formatQty
        // rounds to '0' at 8 qty-decimals. A zero per-level preset is treated as
        // "no preset": preset_sell_qty is null and sells fall back to naive
        // (budget) sizing — identical to a null-preset call.
        $args = [self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000];

        $withTinyPreset = $this->planner()->plan(...$args, fixedQty: null, tick: 10, presetBaseQty: '0.00000001');
        $naive          = $this->planner()->plan(...$args, fixedQty: null, tick: 10, presetBaseQty: null);

        $this->assertNull($withTinyPreset['preset_sell_qty'], 'a preset that rounds to zero per level reverts');
        $this->assertSame(
            array_column($naive['items'], 'quantity'),
            array_column($withTinyPreset['items'], 'quantity'),
            'reverted sizing matches the null-preset plan exactly'
        );
    }

    public function test_preset_base_qty_null_uses_naive_sizing(): void
    {
        $plan = $this->planner()->plan(
            self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000,
            fixedQty: null, tick: 10, presetBaseQty: null
        );

        $this->assertNull($plan['preset_sell_qty']);
        $this->assertNull($plan['preset_base_qty']);
    }

    public function test_preset_base_qty_is_a_no_op_in_buy_mode(): void
    {
        // 'buy' mode has no sell levels, so the preset never engages regardless
        // of value. preset_sell_qty stays null and buys keep budget sizing.
        $plan = $this->planner()->plan(
            self::SYMBOL, 100_000, 4, 1.0, 'buy', 40_000_000,
            fixedQty: null, tick: 10, presetBaseQty: '0.006'
        );

        $this->assertNull($plan['preset_sell_qty'], 'no sell levels -> preset is a no-op');
        foreach ($plan['items'] as $it) {
            $this->assertSame('buy', $it['side']);
        }
        // preset_base_qty is still echoed back (normalized), even though unused.
        $this->assertSame('0.006', $plan['preset_base_qty']);
    }

    // =====================================================================
    // fixedQty
    // =====================================================================

    public function test_fixed_qty_overrides_budget_on_every_level(): void
    {
        // With fixedQty set, budget is ignored for sizing: every level carries
        // the fixed quantity.
        $plan = $this->planner()->plan(
            self::SYMBOL, 100_000, 6, 1.0, 'both', 999_999_999,
            fixedQty: '0.001', tick: 10
        );

        foreach ($plan['items'] as $it) {
            $this->assertSame('0.001', $it['quantity'], 'every level carries fixedQty regardless of budget');
        }
    }

    public function test_preset_wins_over_fixed_qty_on_sells_fixed_wins_on_buys(): void
    {
        // Both passed. plan() checks presetSellQty FIRST inside the sizing loop,
        // so on the SELL side the preset split wins; the BUY side (which the
        // preset never touches) keeps fixedQty. Pin that split precedence.
        $plan = $this->planner()->plan(
            self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000,
            fixedQty: '0.001', tick: 10, presetBaseQty: '0.006'
        );

        foreach ($this->side($plan, 'sell') as $s) {
            $this->assertSame('0.002', $s['quantity'], 'preset split wins on sells');
        }
        foreach ($this->side($plan, 'buy') as $b) {
            $this->assertSame('0.001', $b['quantity'], 'fixedQty wins on buys');
        }
    }

    public function test_fixed_qty_and_preset_base_qty_precedence_answered(): void
    {
        // The Step-2 brief left this OPEN: when BOTH fixedQty and presetBaseQty are
        // passed, which wins? The sizing loop settles it — plan() checks
        // presetSellQty FIRST (`if ($presetSellQty !== null && side === 'sell')`),
        // then falls through to fixedQty. So the precedence is SIDE-SPLIT, not
        // global:
        //   * SELL levels: presetBaseQty wins (its per-sell split); fixedQty is ignored.
        //   * BUY  levels: presetBaseQty never applies, so fixedQty wins.
        // FOOTGUN: a caller passing both and expecting fixedQty on EVERY level gets
        // the preset split on their sells instead — silently. This pins all three
        // configurations side by side as the definitive answer.
        $args = [self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000];

        // (a) fixedQty ALONE -> every level carries the fixed quantity.
        $fixedOnly = $this->planner()->plan(...$args, fixedQty: '0.001', tick: 10, presetBaseQty: null);
        foreach ($fixedOnly['items'] as $it) {
            $this->assertSame('0.001', $it['quantity'], 'fixedQty alone: every level is fixed');
        }

        // (b) presetBaseQty ALONE -> sells split the preset (0.006/3 = 0.002); buys
        // fall back to budget-derived sizing (neither fixed nor preset).
        $presetOnly = $this->planner()->plan(...$args, fixedQty: null, tick: 10, presetBaseQty: '0.006');
        foreach ($this->side($presetOnly, 'sell') as $s) {
            $this->assertSame('0.002', $s['quantity'], 'preset alone: sells split the preset');
        }
        foreach ($this->side($presetOnly, 'buy') as $b) {
            $this->assertNotSame('0.002', $b['quantity'], 'preset alone: buys are budget-sized, not preset');
            $this->assertNotSame('0.001', $b['quantity'], 'preset alone: buys are budget-sized, not fixed');
        }

        // (c) BOTH -> preset wins on sells, fixedQty wins on buys (the split rule).
        $both = $this->planner()->plan(...$args, fixedQty: '0.001', tick: 10, presetBaseQty: '0.006');
        foreach ($this->side($both, 'sell') as $s) {
            $this->assertSame('0.002', $s['quantity'], 'both passed: preset split wins on sells (NOT fixedQty)');
        }
        foreach ($this->side($both, 'buy') as $b) {
            $this->assertSame('0.001', $b['quantity'], 'both passed: fixedQty wins on buys');
        }
    }

    // =====================================================================
    // Reporting aggregates
    // =====================================================================

    public function test_estimated_notional_and_fee_are_consistent_with_the_items(): void
    {
        // Compute the expectation INDEPENDENTLY from the returned items, not by
        // re-calling GridPlanner:
        //   estimated_notional = sum(item.notional)
        //   estimated_fee_irt  = ceil(sum * fee_bps / 10000)
        //                      = intdiv(sum * fee_bps + 9999, 10000)
        config(['trading.exchange.fee_bps' => 35]);

        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', 60_000_000, tick: 10);

        $sumNotional = array_sum(array_column($plan['items'], 'notional'));
        $this->assertSame($sumNotional, $plan['estimated_notional'], 'estimated_notional is the item notional sum');

        $feeBps      = (int) config('trading.exchange.fee_bps');
        $expectedFee = intdiv($sumNotional * $feeBps + 9999, 10000);
        $this->assertSame($expectedFee, $plan['estimated_fee_irt'], 'fee is ceil(sum * fee_bps / 10000)');

        // Sanity anchor for this specific scenario (6 * 10_000_000 = 60_000_000):
        $this->assertSame(60_000_000, $plan['estimated_notional']);
        $this->assertSame(210_000, $plan['estimated_fee_irt']);
    }

    // =====================================================================
    // Determinism
    // =====================================================================

    public function test_ts_field_reflects_the_frozen_clock(): void
    {
        $plan = $this->planner()->plan(self::SYMBOL, 100_000, 6, 1.0, 'both', tick: 10);

        $this->assertSame(self::FROZEN_TS, $plan['ts'], 'ts is now()->timestamp, frozen by setTestNow');
    }

    // =====================================================================
    // Echoed plan metadata
    // =====================================================================

    public function test_plan_echoes_its_inputs_and_symbol_is_upcased(): void
    {
        // symbol is upper-cased/trimmed; the reference price is echoed as 'mid'.
        $plan = $this->planner()->plan('  btcirt  ', 100_000, 6, 1.0, 'both', tick: 10);

        $this->assertSame('BTCIRT', $plan['symbol']);
        $this->assertSame(100_000, $plan['mid']);
        $this->assertSame(6, $plan['levels']);
        $this->assertSame('both', $plan['mode']);
        $this->assertSame(1.0, $plan['step_pct']);
        $this->assertSame(10, $plan['tick']);
    }
}
