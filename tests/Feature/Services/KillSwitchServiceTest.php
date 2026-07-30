<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BotConfig;
use App\Services\KillSwitchService;
use App\Services\MarketDataLayer;
use Database\Factories\BotConfigFactory;
use Database\Factories\CompletedTradeFactory;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 13 — Step 9: characterization tests for {@see KillSwitchService}, the
 * risk-halt gate wired into TradingEngineService::initializeGrid and
 * AdjustGridJob. The single public entry is
 * {@see KillSwitchService::checkAndTrigger()}; before this file it had zero
 * test coverage.
 *
 * DISCIPLINE (same as Steps 1-8): these tests lock the ACTUAL behaviour. Where a
 * behaviour is surprising it is marked `// CHARACTERIZATION:` and reported; no
 * assertion is weakened to make production look correct, no production file /
 * migration / config is touched, and no count or value is delta-matched — every
 * expected string is a hand-derived literal.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CLASS MAP (verified against app/Services/KillSwitchService.php, not the roadmap)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * PUBLIC METHOD
 *   checkAndTrigger(BotConfig $bot): array
 *     returns array{triggered: bool, reason: 'stop_loss'|'max_drawdown'|null,
 *                   details: array}
 *     - no-trigger shape is exactly ['triggered'=>false,'reason'=>null,'details'=>[]]
 *
 * "THRESHOLD SET" GATE (checkAndTrigger:49-50)
 *   A threshold counts as configured only when it is `!== null` AND
 *   `(float) value > 0`. So a null OR a zero (or negative) percent is treated as
 *   UNSET. When NEITHER is set the method returns the no-trigger shape without
 *   reading price or querying trades.
 *
 * EVALUATION ORDER
 *   stop_loss is evaluated FIRST; if it trips, max_drawdown is never reached.
 *
 * THRESHOLD 1 — stop_loss (evaluateStopLoss:80-121)
 *   anchor       = bot.grid_center_price  (the STABLE grid_center_price captured
 *                  at initial placement — NOT the moving center_price). If the
 *                  anchor is null / '' / not positive the check does not apply
 *                  (returns null) and price is never read.
 *   current      = marketData->getLastPrice(bot.symbol ?? 'BTCIRT')  ← the ONE
 *                  injected dependency to control in tests. A thrown Throwable is
 *                  swallowed (logs KILL_SWITCH_PRICE_UNAVAILABLE) and does NOT
 *                  trip — a missing price can never prove a breach.
 *   metric       = abs((current - anchor) / anchor * 100)   → percent distance,
 *                  symmetric (a move UP or DOWN of equal size trips identically).
 *   COMPARISON   = Money::compare(distance, stop_loss_percent) > 0   → STRICT '>'.
 *
 * THRESHOLD 2 — max_drawdown (evaluateMaxDrawdown:128-168)
 *   base         = bot.total_capital. If null / not positive the check does not
 *                  apply (returns null) — this is the divide-by-zero guard.
 *   lossSum      = bcmath SUM of net_profit over completedTrades WHERE
 *                  net_profit < 0 (only LOSING realized trades; winners ignored).
 *                  If the sum is not negative there is no drawdown (returns null).
 *   metric       = abs(lossSum / total_capital * 100)
 *   COMPARISON   = Money::compare(drawdown, max_drawdown_percent) > 0 → STRICT '>'.
 *
 * SIDE EFFECTS (trip:184-203) — only when the bot is CURRENTLY active:
 *   - Log::channel('trading')->warning('KILL_SWITCH_TRIGGERED', [...details])
 *   - bot.is_active   = false
 *   - bot.stop_reason = 'kill_switch:' . reason      ('kill_switch:stop_loss' | …)
 *   - bot.stopped_at  = now()
 *   - bot.save()
 *   When the bot is ALREADY inactive: NO log, NO write, NO save — but the method
 *   still returns triggered=true (idempotent). Nothing is dispatched; existing
 *   orders are deliberately left untouched.
 *
 * SIMULATION: the class NEVER reads bot.simulation. The switch runs identically
 * for simulated and live bots (see test_kill_switch_runs_for_simulation_bots).
 *
 * HARNESS NOTE (why stop_loss "unset" is 0, not null, in these tests): the Step-5
 * BuildsGridSchema declares bot_configs.stop_loss_percent as NOT NULL DEFAULT 5,
 * so this sqlite harness physically cannot persist a null stop_loss_percent —
 * only max_drawdown_percent (and take_profit_percent) are nullable. The gate is a
 * compound `!== null && (float) > 0`, so a stored 0 exercises the SAME "unset"
 * branch a null would (the `> 0` half), and I use 0 wherever a test needs the
 * stop-loss disabled. The genuine null half of the gate is still covered on the
 * nullable side, via max_drawdown_percent = null in the stop-loss and no-op tests.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DETERMINISM: the only non-deterministic inputs are the injected price
 * (a Mockery mock of MarketDataLayer, stubbed per test) and now() (written to
 * stopped_at on a trip). now() is frozen at an EXPLICIT UTC instant — never a
 * naive local literal: the host is Asia/Tehran, the container UTC, and Step 7
 * proved a naive freeze makes a written timestamp host-dependent. stopped_at is
 * asserted by instant equality so it stays timezone-independent.
 *
 * MAGNITUDE: realistic BTCIRT — anchor/prices ~9.8e10 IRT, capital 5e7 IRT, loss
 * sums in the millions of IRT. The largest bcmath intermediate is ~1e11, ~3
 * orders of magnitude below Money's 10^(14-scale) float-cast cliffs, and every
 * value fed to Money here is an int or a decimal string (never a float), so the
 * %.20F float path is not exercised. Under sqlite these magnitudes (< 2^63) are
 * held exactly, so the DECIMAL(20,0) REAL-fallback caveat does not bite.
 */
final class KillSwitchServiceTest extends TestCase
{
    use BuildsGridSchema;

    /** Frozen wall clock, anchored to an explicit UTC instant (see class docblock). */
    private const NOW = '2026-01-01 00:00:00';

    /** Default Kill Switch stop-loss anchor (matches BotConfigFactory). */
    private const ANCHOR = 98_500_000_000;      // ~98.5B IRT / BTC

    /** Default capital base for the drawdown percentage. */
    private const CAPITAL = 50_000_000;         // 50M IRT

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();

        // Freeze at an explicit UTC instant so a tripped stopped_at is identical
        // on every host (the container is UTC, the production host Asia/Tehran).
        \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Service builders — the injected price source is the one thing to control.
    // ─────────────────────────────────────────────────────────────────────────

    /** A service whose market layer returns $price for BTCIRT's last price. */
    private function serviceWithPrice(int $price): KillSwitchService
    {
        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->with('BTCIRT')->andReturn($price);

        return new KillSwitchService($md);
    }

    /** A service whose market layer throws — the price is unavailable. */
    private function serviceWithPriceUnavailable(): KillSwitchService
    {
        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->andThrow(new \RuntimeException('price feed down'));

        return new KillSwitchService($md);
    }

    /**
     * A service that must NEVER be asked for a price. Used to prove a path
     * short-circuits before reading market data (no threshold set, no anchor,
     * or stop-loss unset so only the drawdown branch runs).
     */
    private function serviceExpectingNoPriceLookup(): KillSwitchService
    {
        $md = Mockery::mock(MarketDataLayer::class);
        $md->shouldReceive('getLastPrice')->never();

        return new KillSwitchService($md);
    }

    /**
     * Install a Log spy on the 'trading' channel and return it so a test can
     * assert whether (and how) the switch logged. The service is the only thing
     * touching Log during checkAndTrigger, so a catch-all channel stub is safe.
     */
    private function spyOnTradingLog(): \Mockery\MockInterface
    {
        $channel = Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($channel);

        return $channel;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // stop_loss
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Price sitting exactly on the anchor → distance 0% → comfortably below the
     * 5% stop-loss. Not triggered; the bot stays active and untouched.
     */
    public function test_stop_loss_price_at_anchor_does_not_trigger(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);

        $result = $this->serviceWithPrice(self::ANCHOR)->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active, 'Bot must remain active when the price is on the anchor.');
    }

    /**
     * The exact boundary. price = anchor × 0.95 → distance is EXACTLY 5.00%,
     * equal to stop_loss_percent.
     *
     *   distance = |(93,575,000,000 − 98,500,000,000) / 98,500,000,000| × 100
     *            = |−4,925,000,000 / 98,500,000,000| × 100
     *            = 5   (exact: 98,500,000,000 × 0.05 = 4,925,000,000)
     *
     * CHARACTERIZATION: the comparison is STRICT `>` (Money::compare(...) > 0),
     * so a distance that merely EQUALS the threshold does NOT trip. If the
     * operator were `>=` this exact-boundary case would (incorrectly, per the
     * code) trigger. Bites at any magnitude — the boundary is a pure ratio.
     */
    public function test_stop_loss_exactly_at_threshold_does_not_trigger(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);

        // 98,500,000,000 × 0.95 = 93,575,000,000  → distance exactly 5.00%
        $result = $this->serviceWithPrice(93_575_000_000)->checkAndTrigger($bot);

        $this->assertFalse($result['triggered'], 'Distance == threshold must NOT trip (strict >).');
        $this->assertNull($result['reason']);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * One IRT past the exact boundary. price = 93,574,000,000 (1M IRT below the
     * exact-5% point 93,575,000,000) → distance ≈ 5.00102% > 5% → trips.
     *
     * Paired with the exact-boundary test above, this pins the operator as a
     * strict `>`: equal does not fire, a hair over does.
     */
    public function test_stop_loss_one_step_past_threshold_triggers(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);

        $result = $this->serviceWithPrice(93_574_000_000)->checkAndTrigger($bot);

        $this->assertTrue($result['triggered'], 'A distance strictly greater than the threshold must trip.');
        $this->assertSame('stop_loss', $result['reason']);
        $this->assertFalse($bot->fresh()->is_active);
    }

    /**
     * A clean breach on a DOWN move, asserting every side effect and the exact
     * details payload. price = anchor × 0.94 = 92,590,000,000 → distance 6%.
     *
     *   sub  = 92,590,000,000 − 98,500,000,000 = −5,910,000,000
     *   div  = −5,910,000,000 / 98,500,000,000 = −0.06          (exact)
     *   mul  = −0.06 × 100 = −6  →  abs = "6"
     *   6 > 5  → trip, reason 'stop_loss'
     */
    public function test_stop_loss_below_threshold_trips_and_writes_side_effects(): void
    {
        $expectedNow = now();

        $bot = BotConfigFactory::new()->active()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);
        $this->assertTrue($bot->is_active, 'Pre-condition: the bot starts active.');

        $tradingLog = $this->spyOnTradingLog();

        $result = $this->serviceWithPrice(92_590_000_000)->checkAndTrigger($bot);

        // Return shape.
        $this->assertSame('stop_loss', $result['reason']);
        $this->assertTrue($result['triggered']);
        $this->assertSame([
            'current_price'     => '92590000000',
            'center_price'      => '98500000000',
            'distance_pct'      => '6',
            'stop_loss_percent' => '5.00',
        ], $result['details']);

        // Persisted side effects.
        $fresh = $bot->fresh();
        $this->assertFalse($fresh->is_active, 'The switch must flip is_active false.');
        $this->assertSame('kill_switch:stop_loss', $fresh->stop_reason);
        $this->assertNotNull($fresh->stopped_at);
        // Compare the wall-clock string, not the instant, so the assertion is
        // timezone-independent: the stored value carries no zone and Eloquent
        // rebuilds it tagged UTC on read, so matching the 'Y-m-d H:i:s' rendering
        // of each side pins stopped_at to the frozen now() under any app timezone
        // (a ->utc() or epoch comparison would spuriously fail off-UTC because
        // only one side would be shifted).
        $this->assertSame(
            $expectedNow->format('Y-m-d H:i:s'),
            $fresh->stopped_at->format('Y-m-d H:i:s'),
            'stopped_at must be the frozen now() instant.'
        );

        // The loud audit log fired exactly once, keyed KILL_SWITCH_TRIGGERED.
        $tradingLog->shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context = []) =>
                $message === 'KILL_SWITCH_TRIGGERED'
                && ($context['reason'] ?? null) === 'stop_loss');
    }

    /**
     * The metric is a symmetric ABSOLUTE distance: an equal move UP trips too.
     * price = anchor × 1.06 = 104,410,000,000 → distance +6% → abs 6% → trips.
     *
     * CHARACTERIZATION: stop-loss here is NOT "price fell below a floor" — it is
     * "price moved more than X% from the grid anchor in EITHER direction". A
     * sharp rally away from the anchor trips the same kill as a crash.
     */
    public function test_stop_loss_triggers_symmetrically_on_an_upward_move(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);

        // 98,500,000,000 × 1.06 = 104,410,000,000  → distance +6%
        $result = $this->serviceWithPrice(104_410_000_000)->checkAndTrigger($bot);

        $this->assertTrue($result['triggered'], 'An upward move beyond the threshold must also trip.');
        $this->assertSame('stop_loss', $result['reason']);
        $this->assertSame('6', $result['details']['distance_pct']);
    }

    /**
     * No anchor yet (grid_center_price null → grid never initialized). The
     * stop-loss simply does not apply: it returns before reading price, so the
     * market layer is NEVER queried, and with max_drawdown unset the result is
     * the no-trigger shape.
     */
    public function test_stop_loss_without_anchor_does_not_apply_and_never_reads_price(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => null,
        ]);

        // getLastPrice()->never() inside this service — a call would fail the test.
        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * A transient market-data failure must NOT trip the switch — without a price
     * the breach cannot be proven. getLastPrice() throws; evaluateStopLoss
     * swallows it, logs KILL_SWITCH_PRICE_UNAVAILABLE, and returns null. The bot
     * stays active.
     */
    public function test_stop_loss_price_unavailable_does_not_trip(): void
    {
        $bot = BotConfigFactory::new()->active()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);

        $tradingLog = $this->spyOnTradingLog();

        $result = $this->serviceWithPriceUnavailable()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active, 'A price-feed failure must not kill the bot.');

        // It warned about the unavailable price, and did NOT emit a trip audit.
        $tradingLog->shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'KILL_SWITCH_PRICE_UNAVAILABLE');
        $tradingLog->shouldNotHaveReceived('warning', ['KILL_SWITCH_TRIGGERED', Mockery::any()]);
    }

    /**
     * CHARACTERIZATION: a stop_loss_percent of 0 is treated as UNSET, not as a
     * zero-tolerance kill. The gate is `!== null && (float) > 0`, so 0 fails it —
     * with max_drawdown also unset the switch is a pure no-op and price is never
     * read, even though the price here is wildly off the anchor.
     */
    public function test_zero_stop_loss_percent_is_treated_as_unset(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // max_drawdown
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Realized loss below the limit. One losing trade of 2,000,000 IRT against
     * 50,000,000 capital → drawdown 4% < 5% → not triggered.
     *
     *   drawdown = |−2,000,000 / 50,000,000| × 100 = 4
     */
    public function test_max_drawdown_below_limit_does_not_trigger(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(2_000_000)->create(['bot_config_id' => $bot->id]);

        // stop_loss unset → the price source must never be consulted.
        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * The exact boundary. Loss 2,500,000 / capital 50,000,000 → drawdown EXACTLY
     * 5.00%, equal to max_drawdown_percent.
     *
     *   drawdown = |−2,500,000 / 50,000,000| × 100 = 5   (exact)
     *
     * CHARACTERIZATION: as with stop_loss the comparison is STRICT `>`, so a
     * drawdown that merely equals the limit does NOT trip.
     */
    public function test_max_drawdown_exactly_at_limit_does_not_trigger(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(2_500_000)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertFalse($result['triggered'], 'Drawdown == limit must NOT trip (strict >).');
        $this->assertNull($result['reason']);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * One IRT past the exact boundary. Loss 2,500,001 → drawdown 5.000002% > 5%
     * → trips. The other side of the strict `>` boundary.
     */
    public function test_max_drawdown_one_step_past_limit_triggers(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(2_500_001)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertTrue($result['triggered'], 'A drawdown strictly greater than the limit must trip.');
        $this->assertSame('max_drawdown', $result['reason']);
        $this->assertFalse($bot->fresh()->is_active);
    }

    /**
     * A clean breach, asserting every side effect and the exact details payload.
     * One losing trade of 3,000,000 IRT / 50,000,000 capital → drawdown 6%.
     *
     *   lossSum   = −3,000,000
     *   div       = −3,000,000 / 50,000,000 = −0.06        (exact)
     *   drawdown  = |−0.06 × 100| = "6"
     *   6 > 5 → trip, reason 'max_drawdown'
     */
    public function test_max_drawdown_over_limit_trips_and_writes_side_effects(): void
    {
        $expectedNow = now();

        $bot = BotConfigFactory::new()->active()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(3_000_000)->create(['bot_config_id' => $bot->id]);

        $tradingLog = $this->spyOnTradingLog();

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame('max_drawdown', $result['reason']);
        $this->assertTrue($result['triggered']);
        $this->assertSame([
            'realized_loss'        => '-3000000',
            'total_capital'        => '50000000',
            'drawdown_pct'         => '6',
            'max_drawdown_percent' => '5.00',
        ], $result['details']);

        $fresh = $bot->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('kill_switch:max_drawdown', $fresh->stop_reason);
        $this->assertSame($expectedNow->format('Y-m-d H:i:s'), $fresh->stopped_at->format('Y-m-d H:i:s'));

        $tradingLog->shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context = []) =>
                $message === 'KILL_SWITCH_TRIGGERED'
                && ($context['reason'] ?? null) === 'max_drawdown');
    }

    /**
     * The drawdown is summed from LOSING trades only (net_profit < 0); winners
     * are excluded. Three losers (1,000,000 + 1,500,000 + 500,000 = 3,000,000)
     * plus two default winning trades. The expected loss sum is derived
     * independently of the service:
     *
     *   lossSum = −(1,000,000 + 1,500,000 + 500,000) = −3,000,000
     *   drawdown = |−3,000,000 / 50,000,000| × 100 = 6  → 6 > 5 → trips
     *
     * If a winner had (wrongly) been included the realized_loss would differ, so
     * the exact "−3000000" pins the WHERE net_profit < 0 filter and the bcmath
     * summation together.
     */
    public function test_max_drawdown_sums_only_losing_trades_via_bcmath(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);

        // Winners — must be ignored by the drawdown sum.
        CompletedTradeFactory::new()->winning(310_500)->create(['bot_config_id' => $bot->id]);
        CompletedTradeFactory::new()->winning(420_000)->create(['bot_config_id' => $bot->id]);

        // Losers — the only trades that count.
        CompletedTradeFactory::new()->losing(1_000_000)->create(['bot_config_id' => $bot->id]);
        CompletedTradeFactory::new()->losing(1_500_000)->create(['bot_config_id' => $bot->id]);
        CompletedTradeFactory::new()->losing(500_000)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertTrue($result['triggered']);
        $this->assertSame('max_drawdown', $result['reason']);
        $this->assertSame('-3000000', $result['details']['realized_loss'], 'Winners must not be summed.');
        $this->assertSame('6', $result['details']['drawdown_pct']);
    }

    /**
     * No realized losses (only winning trades) → the loss sum is 0, which is not
     * negative, so there is no drawdown to measure and the switch does not trip.
     */
    public function test_max_drawdown_with_no_losing_trades_does_not_trigger(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->winning(310_500)->create(['bot_config_id' => $bot->id]);
        CompletedTradeFactory::new()->winning(999_999)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * The divide-by-zero guard. total_capital = 0 with a real realized loss on
     * the books: without the positive-capital guard, `lossSum / total_capital`
     * would throw DivisionByZeroError (Money::div rejects a zero divisor). The
     * guard returns null first, so the switch is a safe no-op — no exception, no
     * false halt.
     */
    public function test_max_drawdown_with_zero_capital_does_not_divide_by_zero(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => 0,
        ]);
        CompletedTradeFactory::new()->losing(3_000_000)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // thresholds absent / null / only-one-set
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Both thresholds unset (stop_loss 0, max_drawdown null — see HARNESS NOTE) →
     * the switch short-circuits to the no-trigger shape without reading price or
     * querying trades. A huge loss and a wildly off-anchor price are both present
     * to prove neither branch even runs.
     */
    public function test_no_thresholds_is_a_pure_noop(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(40_000_000)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * Only stop_loss is set (max_drawdown null). A catastrophic realized loss
     * (80% of capital) is on the books, but with the drawdown threshold unset it
     * is never evaluated, and the price sits on the anchor so stop_loss does not
     * trip either → not triggered. Pins that the unset threshold cannot fire.
     */
    public function test_only_stop_loss_set_drawdown_never_fires(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(40_000_000)->create(['bot_config_id' => $bot->id]);

        // Price on the anchor → stop_loss not breached; drawdown unset → ignored.
        $result = $this->serviceWithPrice(self::ANCHOR)->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    /**
     * Only max_drawdown is set (stop_loss disabled via 0). The price is wildly off
     * the anchor (would breach any stop-loss), but with stop_loss unset the price
     * source is NEVER consulted, and the realized loss is below the limit → not
     * triggered. Pins that the unset stop_loss cannot fire (and does no lookup).
     */
    public function test_only_max_drawdown_set_stop_loss_never_fires(): void
    {
        $bot = BotConfigFactory::new()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'grid_center_price'    => self::ANCHOR,
            'total_capital'        => self::CAPITAL,
        ]);
        // Loss well under the 5% limit (2% here).
        CompletedTradeFactory::new()->losing(1_000_000)->create(['bot_config_id' => $bot->id]);

        // getLastPrice()->never(): stop_loss unset must skip the market lookup.
        $result = $this->serviceExpectingNoPriceLookup()->checkAndTrigger($bot);

        $this->assertSame(['triggered' => false, 'reason' => null, 'details' => []], $result);
        $this->assertTrue($bot->fresh()->is_active);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // evaluation order — stop_loss wins when both would trip
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * When BOTH thresholds are breached, stop_loss is evaluated first and short
     * circuits, so the reported reason is 'stop_loss' and the drawdown branch is
     * never reached. Price 6% off the anchor (breaches 5% stop-loss) AND a 6%
     * realized drawdown (breaches the 5% limit).
     */
    public function test_stop_loss_takes_precedence_when_both_would_trip(): void
    {
        $bot = BotConfigFactory::new()->active()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => 5,
            'grid_center_price'    => self::ANCHOR,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(3_000_000)->create(['bot_config_id' => $bot->id]);

        $result = $this->serviceWithPrice(92_590_000_000)->checkAndTrigger($bot);

        $this->assertSame('stop_loss', $result['reason'], 'stop_loss is checked first and short-circuits.');
        $this->assertFalse($bot->fresh()->is_active);
        $this->assertSame('kill_switch:stop_loss', $bot->fresh()->stop_reason);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // already-stopped behaviour
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * A bot that is ALREADY inactive but whose threshold is breached: the switch
     * reports triggered=true (so a caller such as initializeGrid still aborts)
     * but performs NO write and NO log — the one-way job is already done.
     *
     * CHARACTERIZATION: this is idempotent-by-reporting. The prior stop_reason
     * ('manual_stop' from the stopped() state) is preserved, NOT overwritten with
     * 'kill_switch:stop_loss', and no KILL_SWITCH_TRIGGERED audit is emitted a
     * second time. Re-running the kill path on a dead bot cannot corrupt its
     * recorded stop cause or double-log the halt.
     */
    public function test_already_inactive_bot_reports_triggered_without_rewriting(): void
    {
        $bot = BotConfigFactory::new()->stopped()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);
        $this->assertFalse($bot->is_active, 'Pre-condition: the bot is already stopped.');
        $this->assertSame('manual_stop', $bot->stop_reason, 'Pre-condition: prior stop cause recorded.');

        $tradingLog = $this->spyOnTradingLog();

        // Price 6% off the anchor → the threshold IS breached.
        $result = $this->serviceWithPrice(92_590_000_000)->checkAndTrigger($bot);

        // Still reports triggered=true, idempotently.
        $this->assertTrue($result['triggered']);
        $this->assertSame('stop_loss', $result['reason']);

        // But nothing was rewritten: the prior stop cause survives untouched.
        $fresh = $bot->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('manual_stop', $fresh->stop_reason, 'A dead bot must not be re-stamped.');

        // And no second KILL_SWITCH_TRIGGERED audit was logged.
        $tradingLog->shouldNotHaveReceived('warning');
    }

    /**
     * Running the kill path repeatedly on an already-dead bot stays consistent:
     * both calls report triggered=true, and across both there is still no write
     * and no log. Guards against a double-book / double-log on a scheduler retry.
     */
    public function test_re_running_kill_on_a_dead_bot_stays_idempotent(): void
    {
        $bot = BotConfigFactory::new()->stopped()->create([
            'stop_loss_percent'    => 0,   // 0 == unset for the gate; see HARNESS NOTE.
            'max_drawdown_percent' => 5,
            'total_capital'        => self::CAPITAL,
        ]);
        CompletedTradeFactory::new()->losing(3_000_000)->create(['bot_config_id' => $bot->id]);

        $tradingLog = $this->spyOnTradingLog();

        $service = $this->serviceExpectingNoPriceLookup();
        $first  = $service->checkAndTrigger($bot);
        $second = $service->checkAndTrigger($bot->fresh());

        $this->assertTrue($first['triggered']);
        $this->assertSame('max_drawdown', $first['reason']);
        $this->assertTrue($second['triggered']);
        $this->assertSame('max_drawdown', $second['reason']);

        // Prior cause preserved across both runs; no trip audit ever emitted.
        $this->assertSame('manual_stop', $bot->fresh()->stop_reason);
        $tradingLog->shouldNotHaveReceived('warning');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // simulation
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * CHARACTERIZATION: the Kill Switch runs for SIMULATION bots exactly as for
     * live ones — the class never reads bot.simulation. A simulated bot
     * (simulation = true) is killed by real-price thresholds: is_active is
     * flipped false and stop_reason stamped, same as a live bot.
     *
     * Worth a product note: a paper-trading bot being halted (and needing a
     * human to re-activate) because the REAL market moved 6% may or may not be
     * intended, but it is unambiguously what the code does today.
     */
    public function test_kill_switch_runs_for_simulation_bots(): void
    {
        $bot = BotConfigFactory::new()->simulation()->active()->create([
            'stop_loss_percent'    => 5,
            'max_drawdown_percent' => null,
            'grid_center_price'    => self::ANCHOR,
        ]);
        $this->assertTrue($bot->simulation, 'Pre-condition: this is a simulated bot.');

        $result = $this->serviceWithPrice(92_590_000_000)->checkAndTrigger($bot);

        $this->assertTrue($result['triggered'], 'A simulated bot is not exempt from the kill switch.');
        $this->assertSame('stop_loss', $result['reason']);

        $fresh = $bot->fresh();
        $this->assertFalse($fresh->is_active, 'A simulated bot IS killed by real-price thresholds.');
        $this->assertSame('kill_switch:stop_loss', $fresh->stop_reason);
    }
}
