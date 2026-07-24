<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\MarketData;
use App\Services\GridCalculatorService;
use App\Services\GridPlanner;
use App\Services\NobitexService;
use Illuminate\Support\Collection;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * CHARACTERIZATION test suite for the deterministic level-generation core of
 * {@see \App\Services\GridCalculatorService}.
 *
 * GridCalculatorService is ~1935 lines, but almost all of it is reporting and
 * float estimation. This suite (Phase 13 Step 3) tests ONLY the deterministic
 * level generation:
 *   - calculateGridLevels()      (public entry — validation + dispatch + wrap)
 *   - generateGridLevels()       (the per-side split by mode)
 *   - generateLogarithmicGrid()  }
 *   - generateArithmeticGrid()   }  the three price-ladder algorithms
 *   - generateGeometricGrid()    }
 *   - validateGridInputs()       (the input guards)
 * Everything else in the class — enhanceGridLevels, analyzeGridQuality,
 * calculateGridPerformance, calculateExpectedProfit and the risk/ROI/probability
 * helpers — is OUT OF SCOPE: it is float, it never persists a financial record,
 * and it is explicitly deferred in the Phase 13 plan.
 *
 * Discipline is identical to Steps 1 (MoneyTest) and 2 (GridPlannerTest): this
 * records what the code does *today*. Where the actual behaviour differs from
 * the naive expectation the assertion LOCKS THE ACTUAL BEHAVIOUR and a
 * `// CHARACTERIZATION:` comment explains the surprise. Nothing here asks to
 * change the service.
 *
 * WHY THIS EXTENDS Tests\TestCase (like Step 2, not the app-free Step 1):
 * calculateGridLevels() calls Log::warning()/Log::error() (facades), so it needs
 * the Laravel application booted. It still needs NO database and NO market data:
 * the constructor's NobitexService dependency is injected as a bare Mockery mock
 * with NO expectations, so any call to the exchange would raise and fail the
 * test. A green suite therefore PROVES the level generator never touches the
 * exchange. This is a NO-DATABASE test.
 *
 * The four generator/validation methods under test are private, so they are
 * driven directly by reflection: that lets each assertion pin the RAW ladder
 * (before the out-of-scope enhanceGridLevels() decorates it) and the exact
 * exception class/message (before calculateGridLevels() swallows it into an
 * error array).
 *
 * Expected prices are computed INDEPENDENTLY here (by hand / by writing the
 * algorithm's defining formula inline), never by re-calling the service to
 * define its own oracle. Every asserted integer uses a small centre price
 * (1,000,000) and a wide 10% spacing so that centre×factor stays far under 2^53
 * and round(_, 0) collapses the float noise to an unambiguous integer; the one
 * realistic-magnitude pass asserts only shape (monotonicity, side, ratio),
 * which survives any residual float noise.
 *
 * NOTE on value types: the generators finish each price with PHP's native
 * round($price, 0), which returns a FLOAT. So every asserted price is a float
 * literal (e.g. 900000.0), not an int — that is itself a characterised fact
 * (GridPlanner, by contrast, returns integer prices).
 */
final class GridCalculatorServiceTest extends TestCase
{
    private const SYMBOL = 'BTCIRT';

    private NobitexService $nobitex;
    private MarketData $md;
    private GridCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Bare mock, NO expectations: the level generator must never reach the
        // exchange. Any call on this mock raises and fails the test.
        $this->nobitex = Mockery::mock(NobitexService::class);
        $this->service = new GridCalculatorService($this->nobitex);

        // For the GridPlanner cross-check only. Also a bare mock: plan() is
        // always handed an explicit price, so market data is never consulted.
        $this->md = Mockery::mock(MarketData::class);

        // Pin the config GridPlanner reads so a stray local .env cannot move the
        // split cross-check. GridCalculatorService's level generator reads none
        // of these, but setting them is cheap and mirrors GridPlannerTest.
        config([
            'trading.min_order_value_irt'                    => 3_000_000,
            'trading.ticks.BTCIRT'                           => 10,
            'trading.exchange.fee_bps'                       => 35,
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =====================================================================
    // Reflection + extraction helpers
    // =====================================================================

    /** Invoke one of the three private generators and return its items array (sorted ascending by price). */
    private function grid(string $algorithm, float $center, float $spacing, int $buyCount, int $sellCount): array
    {
        $method = match ($algorithm) {
            'arithmetic'  => 'generateArithmeticGrid',
            'geometric'   => 'generateGeometricGrid',
            'logarithmic' => 'generateLogarithmicGrid',
        };

        $ref = new ReflectionMethod(GridCalculatorService::class, $method);
        $ref->setAccessible(true);

        /** @var Collection $col */
        $col = $ref->invokeArgs($this->service, [$center, $spacing, $buyCount, $sellCount]);

        return $col->all();
    }

    /** Invoke the private generateGridLevels() (per-side split by mode). */
    private function generateGridLevels(float $center, float $spacing, int $levels, string $algorithm, string $mode): Collection
    {
        $ref = new ReflectionMethod(GridCalculatorService::class, 'generateGridLevels');
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->service, [$center, $spacing, $levels, $algorithm, $mode]);
    }

    /**
     * Invoke the private validateGridInputs() and return the thrown Throwable,
     * or null if it accepted the inputs. ReflectionMethod::invokeArgs propagates
     * the method's own exception unwrapped.
     */
    private function validate(float $center, float $spacing, int $levels): ?\Throwable
    {
        $ref = new ReflectionMethod(GridCalculatorService::class, 'validateGridInputs');
        $ref->setAccessible(true);

        try {
            $ref->invokeArgs($this->service, [$center, $spacing, $levels]);

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function prices(array $items, ?string $type = null): array
    {
        if ($type !== null) {
            $items = array_filter($items, fn ($it) => $it['type'] === $type);
        }

        return array_values(array_map(fn ($it) => $it['price'], $items));
    }

    private function planner(): GridPlanner
    {
        return new GridPlanner($this->md);
    }

    // =====================================================================
    // ARITHMETIC — constant ABSOLUTE step
    // =====================================================================

    public function test_arithmetic_grid_has_constant_absolute_step_and_correct_sides(): void
    {
        // center=1,000,000, spacing=10% => spacingAmount = 100,000.
        // Expected computed independently as center ∓ spacingAmount*i:
        //   buys : 900k, 800k, 700k, 600k
        //   sells: 1.1M, 1.2M, 1.3M, 1.4M
        $center  = 1_000_000.0;
        $spacing = 10.0;
        $items   = $this->grid('arithmetic', $center, $spacing, 4, 4);

        $buys  = $this->prices($items, 'buy');   // ascending
        $sells = $this->prices($items, 'sell');  // ascending

        // Correct number of levels + correct side assignment.
        $this->assertCount(4, $buys, 'four buy levels');
        $this->assertCount(4, $sells, 'four sell levels');
        $this->assertSame([600000.0, 700000.0, 800000.0, 900000.0], $buys);
        $this->assertSame([1100000.0, 1200000.0, 1300000.0, 1400000.0], $sells);

        foreach ($buys as $p) {
            $this->assertLessThan($center, $p, 'every buy sits strictly below centre');
        }
        foreach ($sells as $p) {
            $this->assertGreaterThan($center, $p, 'every sell sits strictly above centre');
        }

        // The point of the test: the ACTUAL spacing matches the algorithm's
        // definition — a constant absolute step of centre*spacing within a side.
        $step = $center * ($spacing / 100); // 100000.0, computed independently
        foreach ([$buys, $sells] as $side) {
            for ($i = 1; $i < count($side); $i++) {
                $this->assertSame($step, $side[$i] - $side[$i - 1], 'arithmetic step is a constant absolute amount');
            }
        }

        // Strictly monotonic moving away from centre (ascending, all distinct).
        $this->assertSame(count($items), count(array_unique($this->prices($items))), 'prices are distinct');
    }

    // =====================================================================
    // GEOMETRIC — constant RATIO
    // =====================================================================

    public function test_geometric_grid_has_constant_ratio_and_correct_sides(): void
    {
        // center=1,000,000, spacing=10% => ratio = 1.1.
        // sells = center*ratio^i (independently: 1.1M, 1.21M, 1.331M, 1.4641M).
        // buys  = center/ratio^i  (nearest buy = round(1e6/1.1) = 909091).
        $center  = 1_000_000.0;
        $spacing = 10.0;
        $ratio   = 1.1;
        $items   = $this->grid('geometric', $center, $spacing, 4, 4);

        $buys  = $this->prices($items, 'buy');
        $sells = $this->prices($items, 'sell');

        $this->assertCount(4, $buys);
        $this->assertCount(4, $sells);

        // Sells are exact after round(_, 0).
        $this->assertSame([1100000.0, 1210000.0, 1331000.0, 1464100.0], $sells);
        // Nearest-to-centre buy pinned exactly; the rest are pinned by ratio.
        $this->assertSame(909091.0, $buys[count($buys) - 1], 'nearest geometric buy = round(centre / ratio)');

        foreach ($buys as $p) {
            $this->assertLessThan($center, $p);
            $this->assertGreaterThan(0.0, $p);
        }
        foreach ($sells as $p) {
            $this->assertGreaterThan($center, $p);
        }

        // The shape assertion: constant RATIO between adjacent levels (ascending)
        // within each side. Small delta absorbs the round(_, 0) quantisation.
        foreach ([$buys, $sells] as $side) {
            for ($i = 1; $i < count($side); $i++) {
                $this->assertEqualsWithDelta($ratio, $side[$i] / $side[$i - 1], 1e-4, 'geometric ratio is constant');
            }
        }

        $this->assertSame(count($items), count(array_unique($this->prices($items))), 'prices are distinct');
    }

    // =====================================================================
    // LOGARITHMIC — constant RATIO (distinct factor from geometric on the buy side)
    // =====================================================================

    public function test_logarithmic_grid_has_constant_ratio_and_correct_sides(): void
    {
        // center=1,000,000, spacing=10%.
        //   buys  = center*(1-0.1)^i = center*0.9^i (independently: 656100, 729000, 810000, 900000)
        //   sells = center*(1+0.1)^i = center*1.1^i (1.1M, 1.21M, 1.331M, 1.4641M)
        $center  = 1_000_000.0;
        $spacing = 10.0;
        $items   = $this->grid('logarithmic', $center, $spacing, 4, 4);

        $buys  = $this->prices($items, 'buy');
        $sells = $this->prices($items, 'sell');

        $this->assertCount(4, $buys);
        $this->assertCount(4, $sells);
        $this->assertSame([656100.0, 729000.0, 810000.0, 900000.0], $buys);
        $this->assertSame([1100000.0, 1210000.0, 1331000.0, 1464100.0], $sells);

        foreach ($buys as $p) {
            $this->assertLessThan($center, $p);
        }
        foreach ($sells as $p) {
            $this->assertGreaterThan($center, $p);
        }

        // Constant ratio within a side: 0.9 upward through the buys (i.e. each
        // adjacent ascending pair differs by factor 1/0.9), 1.1 through the sells.
        for ($i = 1; $i < count($buys); $i++) {
            $this->assertEqualsWithDelta(1 / 0.9, $buys[$i] / $buys[$i - 1], 1e-4, 'log buy ratio constant');
        }
        for ($i = 1; $i < count($sells); $i++) {
            $this->assertEqualsWithDelta(1.1, $sells[$i] / $sells[$i - 1], 1e-4, 'log sell ratio constant');
        }
    }

    // =====================================================================
    // ARITHMETIC vs GEOMETRIC must DIVERGE at the outer levels
    // =====================================================================

    public function test_arithmetic_and_geometric_diverge_at_the_outer_levels(): void
    {
        // Same centre/spacing/count. At i=1 the two ladders coincide
        // (center*(1+s) == center + center*s); by the outermost level they must
        // differ measurably, otherwise the algorithm choice would be a no-op.
        //   arithmetic outer sell = center*(1+4s)   = 1,400,000
        //   geometric  outer sell = center*(1+s)^4  = 1,464,100
        //   divergence                              =    64,100
        $center  = 1_000_000.0;
        $spacing = 10.0;

        $ari = $this->prices($this->grid('arithmetic', $center, $spacing, 4, 4), 'sell');
        $geo = $this->prices($this->grid('geometric', $center, $spacing, 4, 4), 'sell');

        // Inner level coincides ...
        $this->assertSame($ari[0], $geo[0], 'inner sell coincides (1,100,000)');
        $this->assertSame(1100000.0, $ari[0]);

        // ... outer level diverges, and the gap is pinned exactly.
        $this->assertSame(1400000.0, $ari[3], 'arithmetic outer sell is linear');
        $this->assertSame(1464100.0, $geo[3], 'geometric outer sell compounds');
        $this->assertSame(64100.0, $geo[3] - $ari[3], 'the outer-level divergence is exactly 64,100');
        $this->assertGreaterThan(0.0, $geo[3] - $ari[3]);
    }

    // =====================================================================
    // LOGARITHMIC vs GEOMETRIC — are they actually distinct?
    // =====================================================================

    public function test_logarithmic_and_geometric_are_distinct_algorithms(): void
    {
        // They share the SELL formula (both center*(1+s)^i) but differ on BUYS:
        //   logarithmic buy = center*(1-s)^i   -> 900,000 nearest
        //   geometric   buy = center/(1+s)^i   -> 909,091 nearest
        // So the two are NOT the same algorithm under two names: they agree above
        // the centre and diverge below it. This test pins that fact.
        $center  = 1_000_000.0;
        $spacing = 10.0;

        $log = $this->grid('logarithmic', $center, $spacing, 4, 4);
        $geo = $this->grid('geometric', $center, $spacing, 4, 4);

        $logBuys  = $this->prices($log, 'buy');
        $geoBuys  = $this->prices($geo, 'buy');
        $logSells = $this->prices($log, 'sell');
        $geoSells = $this->prices($geo, 'sell');

        // Sells: byte-identical.
        $this->assertSame($logSells, $geoSells, 'log and geometric produce identical SELL ladders');

        // Buys: genuinely different, pinned at the nearest-to-centre level.
        $this->assertNotSame($logBuys, $geoBuys, 'log and geometric produce different BUY ladders');
        $this->assertSame(900000.0, $logBuys[count($logBuys) - 1], 'nearest log buy = center*(1-s)');
        $this->assertSame(909091.0, $geoBuys[count($geoBuys) - 1], 'nearest geo buy = center/(1+s)');
    }

    // =====================================================================
    // generateGridLevels() — the per-side split by mode
    // =====================================================================

    public function test_both_mode_splits_levels_evenly(): void
    {
        // 'both' => buyCount = sellCount = intval(levels/2).
        $grid  = $this->generateGridLevels(100_000.0, 1.0, 8, 'logarithmic', 'both');
        $items = $grid->all();

        $this->assertCount(4, $this->prices($items, 'buy'), 'both mode: levels/2 buys');
        $this->assertCount(4, $this->prices($items, 'sell'), 'both mode: levels/2 sells');
    }

    public function test_buy_mode_places_every_level_on_the_buy_side(): void
    {
        // 'buy' => buyCount = levels, sellCount = 0.
        $grid  = $this->generateGridLevels(100_000.0, 1.0, 6, 'logarithmic', 'buy');
        $items = $grid->all();

        $this->assertCount(6, $this->prices($items, 'buy'), 'buy mode: all levels are buys');
        $this->assertCount(0, $this->prices($items, 'sell'), 'buy mode: no sells');
        foreach ($items as $it) {
            $this->assertLessThan(100_000.0, $it['price']);
        }
    }

    public function test_sell_mode_places_every_level_on_the_sell_side(): void
    {
        // 'sell' => buyCount = 0, sellCount = levels.
        $grid  = $this->generateGridLevels(100_000.0, 1.0, 6, 'logarithmic', 'sell');
        $items = $grid->all();

        $this->assertCount(6, $this->prices($items, 'sell'), 'sell mode: all levels are sells');
        $this->assertCount(0, $this->prices($items, 'buy'), 'sell mode: no buys');
        foreach ($items as $it) {
            $this->assertGreaterThan(100_000.0, $it['price']);
        }
    }

    public function test_unknown_algorithm_falls_back_to_logarithmic(): void
    {
        // CHARACTERIZATION: the switch in generateGridLevels() has 'logarithmic'
        // as its DEFAULT arm, so an unrecognised algorithm name silently produces
        // the logarithmic ladder rather than raising.
        $unknown = $this->generateGridLevels(100_000.0, 1.0, 8, 'no-such-algo', 'both')->all();
        $log     = $this->generateGridLevels(100_000.0, 1.0, 8, 'logarithmic', 'both')->all();

        $this->assertSame($this->prices($log), $this->prices($unknown), 'unknown algorithm == logarithmic');
    }

    // =====================================================================
    // Per-side split agrees with GridPlanner (two implementations, one rule)
    // =====================================================================

    public function test_per_side_split_agrees_with_gridplanner_in_every_mode(): void
    {
        // GridCalculatorService and GridPlanner are separate implementations of
        // the same per-side rule; they are supposed to agree. Inputs are chosen
        // wide enough (spacing 1%, tick 10) that GridPlanner collapses nothing,
        // so its item counts equal its per_side and can be compared directly.
        $center  = 100_000;
        $spacing = 1.0;

        $cases = [
            'both' => [8, 4, 4],
            'buy'  => [6, 6, 0],
            'sell' => [6, 0, 6],
        ];

        foreach ($cases as $mode => [$levels, $expectBuys, $expectSells]) {
            // GridCalculatorService side counts.
            $items = $this->generateGridLevels((float) $center, $spacing, $levels, 'logarithmic', $mode)->all();
            $calcBuys  = count($this->prices($items, 'buy'));
            $calcSells = count($this->prices($items, 'sell'));

            $this->assertSame($expectBuys, $calcBuys, "calc buys ($mode)");
            $this->assertSame($expectSells, $calcSells, "calc sells ($mode)");

            // GridPlanner side counts for the same inputs.
            $plan = $this->planner()->plan(self::SYMBOL, $center, $levels, $spacing, $mode, tick: 10);
            $planBuys  = count(array_filter($plan['items'], fn ($it) => $it['side'] === 'buy'));
            $planSells = count(array_filter($plan['items'], fn ($it) => $it['side'] === 'sell'));

            $this->assertSame($calcBuys, $planBuys, "buy split agrees with GridPlanner ($mode)");
            $this->assertSame($calcSells, $planSells, "sell split agrees with GridPlanner ($mode)");
        }
    }

    // =====================================================================
    // validateGridInputs() — exact guards, class and message, both boundaries
    // =====================================================================

    public function test_validate_center_price_must_be_positive(): void
    {
        // Guard: centerPrice <= 0. Largest rejected = 0; smallest accepted is any
        // value > 0. Exact bare-\Exception class and Persian message pinned.
        $zero = $this->validate(0.0, 1.0, 8);
        $this->assertSame(\Exception::class, get_class($zero), 'a bare \Exception, not a domain type');
        $this->assertSame('قیمت مرکز باید مثبت باشد', $zero->getMessage());

        $this->assertSame('قیمت مرکز باید مثبت باشد', $this->validate(-1.0, 1.0, 8)->getMessage());

        // Smallest accepted: strictly positive.
        $this->assertNull($this->validate(0.0000001, 1.0, 8), 'any price > 0 is accepted');
    }

    public function test_validate_spacing_range_both_boundaries(): void
    {
        // Guard: spacing < MIN_SPACING (0.5) || spacing > MAX_SPACING (10.0).
        // Below range: smallest ACCEPTED is exactly 0.5; a value below is rejected.
        $this->assertNull($this->validate(100_000.0, 0.5, 8), 'spacing == MIN (0.5) is accepted');
        $below = $this->validate(100_000.0, 0.49, 8);
        $this->assertSame(\Exception::class, get_class($below));
        $this->assertSame('فاصله گرید باید بین 0.5 تا 10 درصد باشد', $below->getMessage());

        // Above range: largest ACCEPTED is exactly 10.0; a value above is rejected.
        $this->assertNull($this->validate(100_000.0, 10.0, 8), 'spacing == MAX (10.0) is accepted');
        $above = $this->validate(100_000.0, 10.01, 8);
        $this->assertSame('فاصله گرید باید بین 0.5 تا 10 درصد باشد', $above->getMessage());
    }

    public function test_validate_level_count_range_both_boundaries(): void
    {
        // Guard: levels < MIN_GRID_LEVELS (4) || levels > MAX_GRID_LEVELS (20).
        // Below: largest rejected = 3, smallest accepted = 4.
        $this->assertNull($this->validate(100_000.0, 1.0, 4), 'levels == MIN (4) accepted');
        $three = $this->validate(100_000.0, 1.0, 3);
        $this->assertSame(\Exception::class, get_class($three));
        // CHARACTERIZATION: 3 is BOTH below range AND odd. The range check runs
        // first, so the message is the RANGE message, not the parity one.
        $this->assertSame('تعداد سطوح باید بین 4 تا 20 باشد', $three->getMessage());

        // Above: largest accepted = 20, smallest rejected = 21.
        $this->assertNull($this->validate(100_000.0, 1.0, 20), 'levels == MAX (20) accepted');
        $this->assertSame('تعداد سطوح باید بین 4 تا 20 باشد', $this->validate(100_000.0, 1.0, 21)->getMessage());
    }

    public function test_validate_rejects_odd_level_count_in_range(): void
    {
        // Guard (runs AFTER the range guard): levels % 2 !== 0. 5 is in [4,20] but
        // odd, so it clears the range check and trips the parity check. The even
        // neighbours 4 and 6 are accepted — that is the "boundary on both sides".
        $five = $this->validate(100_000.0, 1.0, 5);
        $this->assertSame(\Exception::class, get_class($five));
        $this->assertSame('تعداد سطوح باید زوج باشد', $five->getMessage());

        $this->assertNull($this->validate(100_000.0, 1.0, 4), 'even neighbour 4 accepted');
        $this->assertNull($this->validate(100_000.0, 1.0, 6), 'even neighbour 6 accepted');
    }

    public function test_validate_rejects_odd_in_every_mode_unlike_gridplanner(): void
    {
        // Cross-reference DIVERGENCE (do not reconcile): validateGridInputs takes
        // NO mode parameter and rejects an odd level count UNCONDITIONALLY. Its
        // sibling GridPlanner::plan() throws on odd levels ONLY in 'both' mode and
        // accepts odd counts in 'buy'/'sell' (see
        // GridPlannerTest::test_both_mode_odd_levels_throws_with_exact_message and
        // the buy/sell tests). This test pins the GridCalculatorService side; the
        // two implementations genuinely disagree on this rule.
        $this->assertSame('تعداد سطوح باید زوج باشد', $this->validate(100_000.0, 1.0, 5)->getMessage());
        $this->assertSame('تعداد سطوح باید زوج باشد', $this->validate(100_000.0, 1.0, 7)->getMessage());
        $this->assertSame('تعداد سطوح باید زوج باشد', $this->validate(100_000.0, 1.0, 19)->getMessage());
    }

    // =====================================================================
    // calculateGridLevels() — the public wrapper's contract
    // =====================================================================

    public function test_calculate_grid_levels_success_shape(): void
    {
        $res = $this->service->calculateGridLevels(100_000.0, 1.0, 8, 'arithmetic', 'both');

        $this->assertTrue($res['success']);
        $this->assertSame('arithmetic', $res['algorithm_used']);
        $this->assertSame(8, $res['total_levels']);
        $this->assertInstanceOf(Collection::class, $res['grid_levels']);
        $this->assertCount(8, $res['grid_levels']);

        $this->assertCount(4, $res['grid_levels']->where('type', 'buy'));
        $this->assertCount(4, $res['grid_levels']->where('type', 'sell'));

        $this->assertLessThan(100_000.0, $res['price_range']['lowest']);
        $this->assertGreaterThan(100_000.0, $res['price_range']['highest']);
    }

    public function test_calculate_grid_levels_swallows_validation_exception_into_error_array(): void
    {
        // CHARACTERIZATION: validateGridInputs throws a bare \Exception, but
        // calculateGridLevels wraps its whole body in try/catch and converts any
        // throwable into ['success' => false, 'error' => <message>,
        // 'error_code' => 'GRID_CALCULATION_FAILED']. The exception NEVER escapes
        // the public method — a caller must inspect the returned flag.
        $res = $this->service->calculateGridLevels(100_000.0, 1.0, 5, 'logarithmic', 'both');

        $this->assertFalse($res['success']);
        $this->assertSame('تعداد سطوح باید زوج باشد', $res['error']);
        $this->assertSame('GRID_CALCULATION_FAILED', $res['error_code']);
        $this->assertArrayNotHasKey('grid_levels', $res, 'no grid on the failure path');
    }

    public function test_calculate_grid_levels_reports_spacing_guard_as_error_array(): void
    {
        $res = $this->service->calculateGridLevels(100_000.0, 0.4, 8);

        $this->assertFalse($res['success']);
        $this->assertSame('فاصله گرید باید بین 0.5 تا 10 درصد باشد', $res['error']);
        $this->assertSame('GRID_CALCULATION_FAILED', $res['error_code']);
    }

    public function test_calculate_grid_levels_invalid_string_mode_defaults_to_both(): void
    {
        // CHARACTERIZATION: an unrecognised STRING mode is not an error — it logs
        // a warning and silently defaults to 'both' (Phase 11 Step 6: legacy bots
        // may carry a stray mode). So the call succeeds with an even split.
        $res = $this->service->calculateGridLevels(100_000.0, 1.0, 8, 'logarithmic', 'sideways');

        $this->assertTrue($res['success']);
        $this->assertCount(4, $res['grid_levels']->where('type', 'buy'));
        $this->assertCount(4, $res['grid_levels']->where('type', 'sell'));
    }

    public function test_calculate_grid_levels_non_string_mode_defaults_to_both_without_warning(): void
    {
        // CHARACTERIZATION: $mode is intentionally untyped. A NON-string value
        // (here an int, as the GridCalculatorAdvanced Livewire caller passes a
        // stray positional argument) fails the is_string() check, becomes '', and
        // falls through to 'both' — quietly, with no warning log. The call still
        // succeeds with an even split.
        $res = $this->service->calculateGridLevels(100_000.0, 1.0, 8, 'logarithmic', 5);

        $this->assertTrue($res['success']);
        $this->assertCount(4, $res['grid_levels']->where('type', 'buy'));
        $this->assertCount(4, $res['grid_levels']->where('type', 'sell'));
    }

    // =====================================================================
    // Magnitude — a full pass of each algorithm at realistic BTCIRT scale
    // =====================================================================

    public function test_all_algorithms_stay_sane_and_monotonic_at_btcirt_magnitude(): void
    {
        // center ~ 98.5e9 IRT (a realistic BTCIRT price), spacing 1%, 10 levels.
        // Steps 1 and 2 established the Money float-cast precision cliffs sit
        // around 1e14 and are unreachable at real prices; confirm the level
        // generator stays well clear too: every price is finite, positive, on the
        // correct side, strictly monotonic and far below 1e14.
        $center  = 98_500_000_000.0;
        $spacing = 1.0;

        foreach (['logarithmic', 'arithmetic', 'geometric'] as $algo) {
            $items  = $this->grid($algo, $center, $spacing, 5, 5);
            $prices = $this->prices($items);

            $this->assertCount(10, $prices, "$algo returns all 10 levels");
            $this->assertCount(5, $this->prices($items, 'buy'), "$algo: 5 buys");
            $this->assertCount(5, $this->prices($items, 'sell'), "$algo: 5 sells");

            foreach ($this->prices($items, 'buy') as $p) {
                $this->assertGreaterThan(0.0, $p, "$algo buy stays positive");
                $this->assertLessThan($center, $p, "$algo buy stays below centre");
            }
            foreach ($this->prices($items, 'sell') as $p) {
                $this->assertGreaterThan($center, $p, "$algo sell stays above centre");
            }

            // Strictly increasing, no collapsed duplicates, well clear of the cliff.
            $sorted = $prices;
            sort($sorted);
            $this->assertSame($sorted, $prices, "$algo prices are ascending");
            $this->assertSame(count($prices), count(array_unique($prices)), "$algo has no collapsed levels at 1e11 / 1%");
            foreach ($prices as $p) {
                $this->assertLessThan(1e14, $p, "$algo price stays far below the ~1e14 float precision cliff");
            }
        }
    }
}
