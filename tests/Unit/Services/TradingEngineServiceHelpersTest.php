<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BotConfig;
use App\Services\BotActivityLogger;
use App\Services\GridCalculatorService;
use App\Services\GridOrderExecutor;
use App\Services\GridOrderSync;
use App\Services\GridPlanner;
use App\Services\KillSwitchService;
use App\Services\NobitexService;
use App\Services\TradingEngineService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * CHARACTERIZATION test suite for the three PURE PRIVATE helpers of
 * {@see \App\Services\TradingEngineService}:
 *   - evaluateInitializationHealth()  (array in → status array out)
 *   - resolveBotMode()                (BotConfig in → mode string out)
 *   - computePresetBaseQty()          (BotConfig + balance + mid → base qty|null)
 *
 * These are Phase 13 Step 4, same discipline as Steps 1-3
 * (tests/Unit/Support/MoneyTest.php, tests/Unit/Services/GridPlannerTest.php,
 * tests/Unit/Services/GridCalculatorServiceTest.php): this records what the code
 * does *today*. Where the actual behaviour differs from the naive expectation
 * the assertion LOCKS THE ACTUAL BEHAVIOUR and a `// CHARACTERIZATION:` comment
 * explains the surprise. Nothing here asks to change TradingEngineService.
 *
 * SCOPE: only these three PURE helpers. TradingEngineService::initializeGrid —
 * the full live init path that sleeps, reads auth() and drives seven
 * collaborators — is a REFACTOR-FIRST target and is explicitly NOT touched here.
 *
 * WHY THIS EXTENDS Tests\TestCase (like Steps 2-3, not the app-free Step 1):
 * resolveBotMode() and computePresetBaseQty() call config()/Log::channel(...)
 * (facades), and computePresetBaseQty() routes every figure through the bcmath
 * Money helper, so the Laravel application must be booted. It still needs NO
 * database, NO network and NO clock:
 *   - The constructor's SEVEN dependencies are injected as bare Mockery mocks
 *     with NO expectations. Any call on any of them would raise and fail the
 *     test, so a green suite PROVES these helpers never reach the exchange or
 *     any collaborator.
 *   - Every BotConfig is an UNSAVED `new BotConfig([...])` instance. None of the
 *     three helpers reads a relationship or calls save()/refresh(), so no row is
 *     ever persisted. (Per the Step-4 HARD BOUNDARY: if a helper had required a
 *     persisted model we would have STOPPED for it and deferred to Step 5+.
 *     None did.)
 *
 * The three helpers are private, so they are driven directly by reflection.
 *
 * Expected values are computed INDEPENDENTLY here (by hand / by exact integer
 * arithmetic inline), never by re-calling a helper to define its own oracle. In
 * particular the computePresetBaseQty engage case pins the returned quantity
 * against the input and re-derives the engagement band by hand, rather than
 * trusting the helper's own output.
 *
 * MAGNITUDE: realistic BTCIRT values — mid ~98.5e9 IRT (or a clean 1e11 for the
 * threshold-boundary arithmetic; both are realistic BTCIRT prices), budgets in
 * the tens of millions of IRT, base quantities 1e-4..1e-2 BTC. Steps 1-3
 * established the Money float-cast precision cliffs sit near 1e14; every notional
 * here (~1e7..1e8) and every mid (~1e11) stays far clear, so no cliff is reached.
 */
final class TradingEngineServiceHelpersTest extends TestCase
{
    private TradingEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Seven bare mocks, NO expectations. A call to ANY of them raises and
        // fails the test — this is the "never reaches a collaborator" guarantee,
        // enforced. GridPlanner is mocked too, so its own MarketData constructor
        // dependency is never touched.
        $this->engine = new TradingEngineService(
            Mockery::mock(NobitexService::class),
            Mockery::mock(GridCalculatorService::class),
            Mockery::mock(BotActivityLogger::class),
            Mockery::mock(GridPlanner::class),
            Mockery::mock(GridOrderSync::class),
            Mockery::mock(GridOrderExecutor::class),
            Mockery::mock(KillSwitchService::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =====================================================================
    // Reflection drivers
    // =====================================================================

    /** @param array<string,mixed> $placementResult */
    private function health(array $placementResult): array
    {
        $ref = new ReflectionMethod(TradingEngineService::class, 'evaluateInitializationHealth');
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->engine, [$placementResult]);
    }

    private function resolveMode(BotConfig $bot): string
    {
        $ref = new ReflectionMethod(TradingEngineService::class, 'resolveBotMode');
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->engine, [$bot]);
    }

    private function presetBaseQty(BotConfig $bot, ?string $baseAvailable, float $centerPrice): ?string
    {
        $ref = new ReflectionMethod(TradingEngineService::class, 'computePresetBaseQty');
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->engine, [$bot, $baseAvailable, $centerPrice]);
    }

    // =====================================================================
    // resolveBotMode()
    // =====================================================================

    #[DataProvider('recognisedModeProvider')]
    public function test_recognised_modes_resolve_to_themselves(string $mode): void
    {
        // The three canonical modes pass the in_array() allow-list unchanged.
        $bot = new BotConfig(['mode' => $mode]);
        $this->assertSame($mode, $this->resolveMode($bot));
    }

    public static function recognisedModeProvider(): array
    {
        return [
            'buy'  => ['buy'],
            'sell' => ['sell'],
            'both' => ['both'],
        ];
    }

    public function test_null_mode_resolves_to_both(): void
    {
        // CHARACTERIZATION: a legacy bot predating the `mode` column carries a
        // null mode. `(string) (null ?? '')` = '', which is not in the allow-list,
        // so it defaults to 'both' (and logs a warning on the 'trading' channel).
        $bot = new BotConfig(); // mode attribute never set → null
        $this->assertNull($bot->mode, 'precondition: an unset mode reads back as null');
        $this->assertSame('both', $this->resolveMode($bot));
    }

    #[DataProvider('fallbackModeProvider')]
    public function test_empty_whitespace_and_unrecognised_modes_all_fall_back_to_both(string $mode): void
    {
        // CHARACTERIZATION: the input is trim()'d then strtolower()'d before the
        // allow-list check. An empty string, a whitespace-only string, and any
        // unrecognised token ALL normalise to something outside {both,buy,sell}
        // and therefore ALL default to 'both' — pinned individually, not assumed.
        $bot = new BotConfig(['mode' => $mode]);
        $this->assertSame('both', $this->resolveMode($bot));
    }

    public static function fallbackModeProvider(): array
    {
        return [
            'empty string'        => [''],
            'single space'        => [' '],
            'tabs and spaces'     => ["\t  \n"],
            'unrecognised word'   => ['sideways'],
            'numeric-ish garbage' => ['123'],
            'partial match'       => ['buyy'],
        ];
    }

    #[DataProvider('caseAndPaddingProvider')]
    public function test_mode_is_case_insensitive_and_trimmed(string $mode, string $expected): void
    {
        // CHARACTERIZATION: resolveBotMode is NOT case- or whitespace-sensitive.
        // 'BUY' and 'buy' both resolve to 'buy'; surrounding whitespace is
        // stripped. So a stored 'BUY' is honored as a directional buy bot, not
        // rejected into the 'both' default.
        $bot = new BotConfig(['mode' => $mode]);
        $this->assertSame($expected, $this->resolveMode($bot));
    }

    public static function caseAndPaddingProvider(): array
    {
        return [
            'BUY upper'      => ['BUY', 'buy'],
            'Sell mixed'     => ['Sell', 'sell'],
            'BOTH upper'     => ['BOTH', 'both'],
            'padded buy'     => ['  buy  ', 'buy'],
            'padded upper'   => [" SELL\t", 'sell'],
        ];
    }

    // =====================================================================
    // evaluateInitializationHealth()
    //
    // The ACTUAL rule as found in the code (evaluateInitializationHealth,
    // TradingEngineService.php:313):
    //   1. successful === 0                 → 'failed'.
    //   2. otherwise compute ratio = successful / total  (bcmath; '0' if total<=0)
    //      and sideMinimumMet:
    //        - both sides planned (plannedBuy>0 && plannedSell>0):
    //              successfulBuy>=1 AND successfulSell>=1
    //        - only buys planned (plannedBuy>0):    successfulBuy>=1
    //        - only sells planned (plannedSell>0):  successfulSell>=1
    //        - neither planned:                     true (trivially)
    //   3. sideMinimumMet AND ratio >= 0.8  → 'running'      (comparison is >=)
    //      otherwise                        → 'partially_initialized'.
    // =====================================================================

    public function test_zero_successful_is_failed_regardless_of_total(): void
    {
        // Branch 1: the very first guard. 0 placed → failed, with the exact
        // "0/{total}" phrasing pinned.
        $res = $this->health([
            'total' => 6, 'successful' => 0,
            'planned_buy' => 3, 'planned_sell' => 3,
            'successful_buy' => 0, 'successful_sell' => 0,
        ]);

        $this->assertSame('failed', $res['init_status']);
        $this->assertSame('Grid initialization failed: 0/6 orders placed successfully', $res['reason']);
    }

    public function test_full_success_both_sides_is_running(): void
    {
        // ratio = 10/10 = 1.0 >= 0.8 and both sides have fills → running, reason null.
        $res = $this->health([
            'total' => 10, 'successful' => 10,
            'planned_buy' => 5, 'planned_sell' => 5,
            'successful_buy' => 5, 'successful_sell' => 5,
        ]);

        $this->assertSame('running', $res['init_status']);
        $this->assertNull($res['reason']);
    }

    public function test_ratio_exactly_at_eighty_percent_is_running_comparison_is_inclusive(): void
    {
        // CHARACTERIZATION: the threshold comparison is Money::compare(ratio,'0.8')
        // >= 0, i.e. INCLUSIVE. Exactly 8/10 = 0.8 counts as running, not partial.
        // A single-sided (sell-only) plan isolates the ratio: the per-side minimum
        // is trivially satisfied (>=1 sell filled), so only the 80% bar is in play.
        $res = $this->health([
            'total' => 10, 'successful' => 8,
            'planned_buy' => 0, 'planned_sell' => 10,
            'successful_buy' => 0, 'successful_sell' => 8,
        ]);

        $this->assertSame('running', $res['init_status'], 'ratio == 0.8 is running (>= threshold)');
        $this->assertNull($res['reason']);
    }

    public function test_one_placement_below_the_threshold_is_partial(): void
    {
        // The matching just-below case: 7/10 = 0.7 < 0.8 → partial. Same sell-only
        // shape as the exactly-at test, so the ONLY thing that changed is one
        // fewer fill crossing the 80% line.
        $res = $this->health([
            'total' => 10, 'successful' => 7,
            'planned_buy' => 0, 'planned_sell' => 10,
            'successful_buy' => 0, 'successful_sell' => 7,
        ]);

        $this->assertSame('partially_initialized', $res['init_status'], 'ratio 0.7 < 0.8 is partial');
        // Pin the partial reason format so a refactor announces itself.
        $this->assertStringContainsString('7/10 orders placed (buy 0/0, sell 7/10)', $res['reason']);
    }

    public function test_high_ratio_but_one_planned_side_gets_zero_fills_is_partial(): void
    {
        // CHARACTERIZATION: the per-side minimum can veto a healthy-looking ratio.
        // planned_buy=1, planned_sell=9; every sell fills but the lone buy does
        // not → successful 9/10 = 0.9 (well above 0.8), yet sideMinimumMet is
        // (successfulBuy>=1 && successfulSell>=1) = (0>=1 && 9>=1) = false. So the
        // grid is classified 'partially_initialized' DESPITE the 90% fill rate.
        $res = $this->health([
            'total' => 10, 'successful' => 9,
            'planned_buy' => 1, 'planned_sell' => 9,
            'successful_buy' => 0, 'successful_sell' => 9,
        ]);

        $this->assertSame('partially_initialized', $res['init_status']);
    }

    public function test_per_side_minimum_satisfied_on_both_sides_is_running(): void
    {
        // The counterpart to the veto test: same 9/10 ratio, same lopsided plan,
        // but now the single buy DID fill → both planned sides have >=1 fill and
        // the ratio clears 0.8 → running.
        $res = $this->health([
            'total' => 10, 'successful' => 9,
            'planned_buy' => 1, 'planned_sell' => 9,
            'successful_buy' => 1, 'successful_sell' => 8,
        ]);

        $this->assertSame('running', $res['init_status']);
        $this->assertNull($res['reason']);
    }

    public function test_directional_buy_only_grid_is_running_not_misclassified(): void
    {
        // CHARACTERIZATION (the specific concern from the Step-4 brief): a
        // buy-only grid has ZERO planned sells by design. The per-side minimum
        // does NOT assume two sides — the `elseif ($plannedBuy > 0)` arm only
        // requires successfulBuy>=1. So a fully-filled buy-only grid is correctly
        // 'running', NOT misclassified as partial. The health rule handles
        // directional grids correctly.
        $res = $this->health([
            'total' => 5, 'successful' => 5,
            'planned_buy' => 5, 'planned_sell' => 0,
            'successful_buy' => 5, 'successful_sell' => 0,
        ]);

        $this->assertSame('running', $res['init_status'], 'a healthy buy-only grid is running');
        $this->assertNull($res['reason']);
    }

    public function test_directional_sell_only_grid_is_running_not_misclassified(): void
    {
        // Symmetric to the buy-only case: zero planned buys, all sells filled →
        // running via the `elseif ($plannedSell > 0)` arm (successfulSell>=1).
        $res = $this->health([
            'total' => 5, 'successful' => 5,
            'planned_buy' => 0, 'planned_sell' => 5,
            'successful_buy' => 0, 'successful_sell' => 5,
        ]);

        $this->assertSame('running', $res['init_status'], 'a healthy sell-only grid is running');
        $this->assertNull($res['reason']);
    }

    public function test_zero_planned_orders_hits_the_failed_guard_not_a_division_by_zero(): void
    {
        // Division-by-zero safety, realistic path: when nothing was planned,
        // total=0 and successful=0, so the successful===0 guard returns 'failed'
        // BEFORE the ratio division is ever reached. No DivisionByZeroError.
        $res = $this->health([
            'total' => 0, 'successful' => 0,
            'planned_buy' => 0, 'planned_sell' => 0,
            'successful_buy' => 0, 'successful_sell' => 0,
        ]);

        $this->assertSame('failed', $res['init_status']);
        $this->assertSame('Grid initialization failed: 0/0 orders placed successfully', $res['reason']);
    }

    public function test_division_guard_returns_zero_ratio_when_total_is_zero_but_successful_positive(): void
    {
        // CHARACTERIZATION / division-by-zero guard: with the (inconsistent, but
        // defensively handled) input total=0 while successful>0, the successful
        // ===0 guard is skipped, and the `$total > 0 ? Money::div(...) : '0'`
        // ternary yields ratio '0' rather than dividing by zero. '0' < '0.8' →
        // partial. The point of the test is that NO \DivisionByZeroError escapes.
        $res = $this->health([
            'total' => 0, 'successful' => 1,
            'planned_buy' => 0, 'planned_sell' => 0,
            'successful_buy' => 0, 'successful_sell' => 0,
        ]);

        $this->assertSame('partially_initialized', $res['init_status']);
    }

    public function test_missing_side_keys_default_to_zero_and_do_not_error(): void
    {
        // CHARACTERIZATION: planned_/successful_ side keys are read with `?? 0`.
        // A placementResult carrying only total+successful therefore has both
        // planned sides = 0 → sideMinimumMet is trivially true, so classification
        // rests on the ratio alone. 10/10 = 1.0 → running.
        $res = $this->health(['total' => 10, 'successful' => 10]);

        $this->assertSame('running', $res['init_status']);
        $this->assertNull($res['reason']);
    }

    // =====================================================================
    // computePresetBaseQty() — Phase 11 Step 5 balance-aware sell sizing.
    //
    // Returns the base quantity to hand GridPlanner as presetBaseQty, or null
    // to revert to naive (pre-Step-5) sizing. Null triggers, in code order:
    //   a. $baseAvailable === null                      (simulation contract)
    //   b. mode === 'buy'                                (no sell side to back)
    //   c. base not positive after normalize
    //   d. mid <= 0  OR  budget <= 0
    //   e. base notional > budget                        (all-crypto account)
    //   f. base notional < threshold                     (not worth restructuring)
    //   otherwise → engage, returning the normalized base string.
    //
    // threshold = (mode==='sell' ? budget : budget/2) * 0.5
    //           = budget/2 for sell-only, budget/4 for both.
    // Engagement band is INCLUSIVE at both ends: threshold <= notional <= budget.
    // =====================================================================

    /** A live (non-simulation) sell bot with the given IRT budget. */
    private function sellBot(int $budgetIrt): BotConfig
    {
        return new BotConfig([
            'mode'          => 'sell',
            'simulation'    => false,
            'total_capital' => $budgetIrt,
        ]);
    }

    public function test_simulation_bot_returns_null_via_the_null_balance_contract(): void
    {
        // THE most important assertion in this helper: a simulation bot must
        // return null so simulation stays deterministic and independent of real
        // exchange balances.
        //
        // CHARACTERIZATION of the MECHANISM: computePresetBaseQty does NOT read
        // $botConfig->simulation itself. It relies on the caller's contract that
        // simulation bots never fetch a balance and so pass $baseAvailable = null
        // (the `$baseAvailable === null` guard is the very first line). We pin the
        // behaviour the way the live code invokes it: a simulation bot + null
        // balance → null, even with an otherwise perfectly engageable mid.
        $bot = new BotConfig([
            'mode'          => 'sell',
            'simulation'    => true,
            'total_capital' => 40_000_000,
        ]);

        $this->assertNull($this->presetBaseQty($bot, null, 98_500_000_000.0));
    }

    public function test_null_balance_returns_null_even_for_a_live_sell_bot(): void
    {
        // The same first guard, isolated: a LIVE bot with a null balance (balance
        // API unavailable / not fetched) fails safe to the naive plan.
        $bot = $this->sellBot(40_000_000);
        $this->assertNull($this->presetBaseQty($bot, null, 98_500_000_000.0));
    }

    #[DataProvider('buyModeProvider')]
    public function test_buy_only_mode_returns_null(string $mode): void
    {
        // Guard (b): a buy-only bot has no sell levels to back with inventory, so
        // the helper returns null regardless of how much base is on hand. The
        // mode is trim()'d + strtolower()'d first, so 'BUY' and ' buy ' also hit
        // this guard (case-insensitive, like resolveBotMode).
        $bot = new BotConfig([
            'mode'          => $mode,
            'simulation'    => false,
            'total_capital' => 40_000_000,
        ]);

        // A generous, otherwise-engageable balance — proves buy-mode short-circuits
        // BEFORE any threshold maths.
        $this->assertNull($this->presetBaseQty($bot, '0.005', 98_500_000_000.0));
    }

    public static function buyModeProvider(): array
    {
        return [
            'lower buy'  => ['buy'],
            'upper BUY'  => ['BUY'],
            'padded buy' => ['  buy '],
        ];
    }

    #[DataProvider('nonPositiveBaseProvider')]
    public function test_non_positive_base_returns_null(string $base): void
    {
        // Guard (c): after Money::normalize, a base that is not strictly positive
        // (zero, sub-scale dust, or negative) → null. This is the common "nothing
        // on hand" case.
        $bot = $this->sellBot(40_000_000);
        $this->assertNull($this->presetBaseQty($bot, $base, 98_500_000_000.0));
    }

    public static function nonPositiveBaseProvider(): array
    {
        return [
            'zero'            => ['0'],
            'zero decimals'   => ['0.00000000'],
            'negative'        => ['-0.001'],
            // sub-scale dust truncates to zero at the Money default scale (20 dp),
            // so it is treated as "not positive" — mirrors MoneyTest's 1e-21 case.
            'sub-scale dust'  => ['0.000000000000000000001'],
        ];
    }

    #[DataProvider('nonPositiveMidProvider')]
    public function test_non_positive_mid_returns_null(float $centerPrice): void
    {
        // Guard (d), mid side: mid = (int) round($centerPrice). A non-positive
        // center price (or one that rounds down to 0, like 0.4) yields mid <= 0
        // → null. Base and budget are both valid, so the mid guard is what fires.
        $bot = $this->sellBot(40_000_000);
        $this->assertNull($this->presetBaseQty($bot, '0.003', $centerPrice));
    }

    public static function nonPositiveMidProvider(): array
    {
        return [
            'zero'                 => [0.0],
            'negative'            => [-98_500_000_000.0],
            // CHARACTERIZATION: a tiny positive price rounds to mid 0 and is
            // treated as non-positive. Unreachable at real BTCIRT prices (~1e11).
            'rounds down to zero' => [0.4],
        ];
    }

    #[DataProvider('nonPositiveBudgetProvider')]
    public function test_non_positive_budget_returns_null(int $budgetIrt): void
    {
        // Guard (d), budget side: (int) $botConfig->total_capital <= 0 → null.
        // Base and mid are both valid, so the budget guard is what fires.
        $bot = $this->sellBot($budgetIrt);
        $this->assertNull($this->presetBaseQty($bot, '0.003', 98_500_000_000.0));
    }

    public static function nonPositiveBudgetProvider(): array
    {
        return [
            'zero budget'     => [0],
            'negative budget' => [-40_000_000],
        ];
    }

    public function test_holdings_exceeding_the_whole_budget_returns_null(): void
    {
        // Guard (e): base notional > budget → the account is effectively all
        // crypto with no room for a normal grid → null.
        //   mid=1e11, budget=40,000,000, base=0.0005 BTC
        //   notional = 0.0005 * 1e11 = 50,000,000 > 40,000,000  → null
        $bot = $this->sellBot(40_000_000);
        $this->assertNull($this->presetBaseQty($bot, '0.0005', 100_000_000_000.0));
    }

    public function test_holdings_exactly_at_the_budget_ceiling_still_engage(): void
    {
        // Boundary above (e): the reject is compare(notional, budget) > 0, so
        // notional == budget is NOT rejected. With base worth exactly the budget
        // (and >= threshold) the helper ENGAGES and returns the full base.
        //   mid=1e11, budget=40,000,000, base=0.0004 → notional 40,000,000 == budget
        //   threshold (sell) = 40,000,000 / 2 = 20,000,000, so 40M >= 20M → engage
        $bot = $this->sellBot(40_000_000);
        $this->assertSame('0.0004', $this->presetBaseQty($bot, '0.0004', 100_000_000_000.0));
    }

    public function test_holdings_below_the_engagement_threshold_returns_null(): void
    {
        // Guard (f), just below threshold. Sell-only threshold = budget/2.
        //   mid=1e11, budget=40,000,000 → threshold = 20,000,000
        //   base=0.00019 → notional 19,000,000 < 20,000,000 → null
        $bot = $this->sellBot(40_000_000);
        $this->assertNull($this->presetBaseQty($bot, '0.00019', 100_000_000_000.0));
    }

    public function test_holdings_exactly_at_the_engagement_threshold_engage(): void
    {
        // Boundary below (f): the reject is compare(notional, threshold) < 0, so
        // notional == threshold ENGAGES (inclusive).
        //   mid=1e11, budget=40,000,000 → threshold = 20,000,000
        //   base=0.0002 → notional exactly 20,000,000 == threshold → engage
        $bot = $this->sellBot(40_000_000);
        $this->assertSame('0.0002', $this->presetBaseQty($bot, '0.0002', 100_000_000_000.0));
    }

    public function test_both_mode_uses_a_quarter_budget_threshold(): void
    {
        // CHARACTERIZATION: the threshold is mode-dependent. In 'both' mode
        // GridPlanner splits the budget across two sides, so
        //   naiveSellNotional = budget/2  and  threshold = (budget/2)*0.5 = budget/4.
        //   mid=1e11, budget=40,000,000 → threshold = 10,000,000
        //   base=0.0001 → notional exactly 10,000,000 == threshold → engage
        //   base=0.00009 → notional 9,000,000 < 10,000,000 → null
        // (In sell-only mode this same 0.0001 base is BELOW its 20,000,000
        // threshold and would revert — the mode genuinely moves the bar.)
        $both = new BotConfig(['mode' => 'both', 'simulation' => false, 'total_capital' => 40_000_000]);

        $this->assertSame('0.0001', $this->presetBaseQty($both, '0.0001', 100_000_000_000.0));
        $this->assertNull($this->presetBaseQty($both, '0.00009', 100_000_000_000.0));

        // Same 0.0001 base, sell-only mode: 10,000,000 < 20,000,000 threshold → null.
        $sell = $this->sellBot(40_000_000);
        $this->assertNull($this->presetBaseQty($sell, '0.0001', 100_000_000_000.0));
    }

    public function test_engage_case_returns_the_full_base_quantity(): void
    {
        // The headline engage case at a realistic BTCIRT mid. Expected value
        // computed INDEPENDENTLY, not by re-calling the helper:
        //   mid    = (int) round(98,500,000,000)      = 98,500,000,000
        //   budget = 40,000,000 IRT, mode = sell
        //   naiveSellNotional (sell-only) = budget = 40,000,000
        //   threshold = naiveSellNotional * 0.5 = 20,000,000
        //   base   = 0.0003 BTC
        //   notional = 0.0003 * 98,500,000,000 = 29,550,000 IRT
        //   20,000,000 <= 29,550,000 <= 40,000,000  → ENGAGE
        //   return = full available base = normalize('0.0003') = '0.0003'
        // The helper "dedicates the full available base to the sell side", so the
        // returned quantity is the input base itself (normalized), NOT a per-level
        // split — GridPlanner performs the split downstream (see GridPlannerTest).
        $bot = $this->sellBot(40_000_000);

        $result = $this->presetBaseQty($bot, '0.0003', 98_500_000_000.0);

        $this->assertSame('0.0003', $result, 'engage returns the full normalized base quantity');

        // Independent re-check of the engagement band, spelled out with integers
        // so the assertion does not lean on the helper's own arithmetic.
        $mid              = 98_500_000_000;
        $budget           = 40_000_000;
        $notional         = 0.0003 * $mid;          // 29,550,000
        $threshold        = $budget * 0.5;          // 20,000,000  (sell-only: naiveSellNotional = budget)
        $this->assertSame(29_550_000.0, $notional);
        $this->assertSame(20_000_000.0, $threshold);
        $this->assertGreaterThanOrEqual($threshold, $notional);
        $this->assertLessThanOrEqual((float) $budget, $notional);
    }

    public function test_engage_result_stays_far_below_the_money_float_cliff(): void
    {
        // Magnitude sanity: at BTCIRT scale the notional (~3e7) and mid (~1e11)
        // sit far below the ~1e14 Money float-cast cliff characterised in Steps
        // 1-3, so computePresetBaseQty engages cleanly with an exact bcmath
        // notional. A slightly larger-but-still-realistic holding confirms it:
        //   mid=98.5e9, budget=80,000,000, base=0.0006 → notional 59,100,000
        //   threshold = 40,000,000; 40M <= 59.1M <= 80M → engage.
        $bot = $this->sellBot(80_000_000);

        $this->assertSame('0.0006', $this->presetBaseQty($bot, '0.0006', 98_500_000_000.0));

        $notional = 0.0006 * 98_500_000_000; // 59,100,000
        $this->assertSame(59_100_000.0, $notional);
        $this->assertLessThan(1e14, $notional, 'notional stays clear of the Money precision cliff');
    }
}
