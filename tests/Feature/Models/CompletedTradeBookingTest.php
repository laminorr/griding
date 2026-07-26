<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 13 — Step 7: Characterization tests for
 * {@see CompletedTrade::createFromOrders()}, the ONLY code path that persists a
 * completed trade. Every column it writes is computed with the bcmath Money
 * helper on decimal strings; before this file it had zero test coverage.
 *
 * DISCIPLINE (same as Steps 1-5): these tests lock the ACTUAL behaviour. Where
 * the behaviour is surprising it is marked `// CHARACTERIZATION:` and reported;
 * no assertion is weakened to make production look correct, and no production
 * file is touched to match an expectation.
 *
 * ORACLES: every expected number below is derived independently of the code
 * under test — by hand in the doc-comment arithmetic, and the exact expected
 * strings are literals. createFromOrders is never called to define its own
 * oracle.
 *
 * MAGNITUDE: realistic BTCIRT — prices ~9.8e10 IRT/BTC, amounts 1e-4…1e-3 BTC,
 * notionals 1.6e7…1e8 IRT. Money::normalize is only ever handed integers and
 * decimal strings here (GridOrder->price is an int-valued DECIMAL(20,0) read
 * uncast; ->amount is a decimal:8 string), so the %.20F float path — and the
 * 10^(14-scale) float-cast cliffs pinned in Steps 1-3 — is never exercised.
 * The largest intermediate is a sell notional ~1e8, ~11 orders of magnitude
 * below the scale-8 cliff at 1e6… (note: those cliffs bite float INPUTS to
 * Money; here there are none).
 *
 * DETERMINISM: createFromOrders reads cache('btc_price'), now(), and
 * detectMarketTrend()'s two cache keys. Carbon::setTestNow pins now(); the
 * array cache store is seeded (or left cold) explicitly per test.
 */
final class CompletedTradeBookingTest extends TestCase
{
    use BuildsGridSchema;

    /** Fixed wall-clock so now()/toISOString() and the timestamps are pinned. */
    private const NOW = '2026-01-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        // Freeze at an explicit UTC instant, not a naive literal parsed in the
        // app's local timezone. now()->toISOString() serialises in UTC, so a
        // local-timezone freeze would make the pinned timestamp host-dependent
        // (e.g. 3.5h earlier under Asia/Tehran). Anchoring the clock to UTC keeps
        // it identical on every host.
        \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse(self::NOW, 'UTC'));

        // Cold cache by default. Individual tests seed btc_price* explicitly.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        $this->dropGridSchema();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a filled buy/sell GridOrder pair with explicit created_at/updated_at.
     *
     * buyOrder.created_at and sellOrder.updated_at drive execution_time_seconds
     * (see test_execution_time_seconds_*). By default the sell is updated two
     * minutes after the buy is created, i.e. a 120-second round-trip.
     *
     * @param string $buyAmount  buy leg `amount` column
     * @param string $sellAmount sell leg `amount` column (defaults to buyAmount)
     */
    private function filledPair(
        int $botId,
        string $buyPrice,
        string $sellPrice,
        string $buyAmount,
        ?string $sellAmount = null,
        ?string $buyFilledAmount = null,
        string $buyCreatedAt = self::NOW,
        string $sellUpdatedAt = '2026-01-01 00:02:00',
    ): array {
        $sellAmount ??= $buyAmount;

        $buy = GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $botId,
            'price'         => $buyPrice,
            'amount'        => $buyAmount,
            'filled_amount' => $buyFilledAmount ?? $buyAmount,
            'created_at'    => $buyCreatedAt,
            'updated_at'    => $buyCreatedAt,
        ]);

        $sell = GridOrderFactory::new()->sell()->filled()->create([
            'bot_config_id' => $botId,
            'price'         => $sellPrice,
            'amount'        => $sellAmount,
            'filled_amount' => $sellAmount,
            'created_at'    => $buyCreatedAt,
            'updated_at'    => $sellUpdatedAt,
        ]);

        return [$buy, $sell];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The arithmetic — exact value of every computed column
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Clean winning round-trip with an EXPLICIT non-default fee_bps read straight
     * off the buy order's bot (fee_bps = 20, distinguishable from the config
     * default of 35). Hand-derived oracle:
     *
     *   buyPrice  = 98,000,000,000    sellPrice = 99,000,000,000
     *   amount    = 0.001 (both legs)
     *   gross     = (99e9 − 98e9) × 0.001                       = 1,000,000
     *   buyNot    = 98e9 × 0.001                                =    98,000,000
     *   sellNot   = 99e9 × 0.001                                =    99,000,000
     *   feeRate   = 20 / 10000                                  = 0.002
     *   fee       = 0.002 × (98,000,000 + 99,000,000)           =   394,000
     *   net       = 1,000,000 − 394,000                         =   606,000
     *   profit    = net                                         =   606,000
     *   net_profit= net                                         =   606,000
     *   pct       = (1,000,000 / 98,000,000) × 100              = 1.020408163…%
     */
    public function test_books_exact_values_for_a_clean_pair_with_explicit_fee_bps(): void
    {
        $bot = BotConfigFactory::new()->live()->create(['fee_bps' => 20]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // Prices/amount round-trip exactly at this magnitude (values ~1e11 are
        // far below sqlite's ~9.2e18 integer→REAL cliff).
        $this->assertSame('98000000000.00000000', $trade->buy_price);
        $this->assertSame('99000000000.00000000', $trade->sell_price);
        $this->assertSame('0.00100000', $trade->amount);

        // gross_profit = (sellPrice − buyPrice) × amount
        $this->assertSame('1000000.00000000', $trade->gross_profit);

        // fee = feeRate × (buyNotional + sellNotional), feeRate = 20/10000
        $this->assertSame('394000.00000000', $trade->fee);

        // profit and net_profit are both the NET figure.
        $this->assertSame('606000.00000000', $trade->profit);
        $this->assertSame('606000.00000000', $trade->net_profit);

        // profit_percentage is GROSS/buyNotional×100, read back through the
        // model's decimal:4 cast (HALF_UP): 1.020408163… → "1.0204".
        $this->assertSame('1.0204', $trade->profit_percentage);

        $this->assertSame('grid', $trade->trade_type);
        $this->assertNull($trade->grid_level_buy);
        $this->assertNull($trade->grid_level_sell);
    }

    /**
     * The fee rate falls back to config('trading.exchange.fee_bps', 35) when the
     * buy order has no bot to read fee_bps from. The config value is overridden
     * to 50 (0.5%) here so the fallback is PROVABLE: a 50-bps fee is distinct
     * from every other fee_bps this file uses.
     *
     *   feeRate = 50/10000 = 0.005
     *   fee     = 0.005 × 197,000,000 = 985,000
     *   net     = 1,000,000 − 985,000 = 15,000
     */
    public function test_fee_rate_falls_back_to_config_when_bot_config_is_absent(): void
    {
        config(['trading.exchange.fee_bps' => 50]);

        $bot = BotConfigFactory::new()->create(['fee_bps' => 20]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        // No bot to read fee_bps from: `$buyOrder->botConfig?->fee_bps` is null,
        // so `?? config(...)` engages. (In production this is the realistic
        // trigger — the DECIMAL column is NOT NULL default 35, so an absent
        // relation, not a null column, is what makes the coalesce fire.)
        $buy->setRelation('botConfig', null);

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('985000.00000000', $trade->fee);
        $this->assertSame('15000.00000000', $trade->net_profit);
        $this->assertSame('15000.00000000', $trade->profit);
    }

    /**
     * The `?? config()` fallback is a NULL-coalesce, so it distinguishes a null
     * fee_bps from a zero fee_bps:
     *   - fee_bps = null  → coalesce fires  → config (here 50 bps) is used.
     *   - fee_bps = 0     → coalesce is skipped (0 is not null) → feeRate 0,
     *                       zero fee, net == gross. Config is NOT consulted.
     *
     * CHARACTERIZATION: a bot deliberately configured with fee_bps = 0 pays no
     * fee and never falls back to the exchange default. At BTCIRT notionals of
     * ~2e8 IRT the default 35-bps fee it silently skips is ~689,500 IRT per
     * round-trip, so a mis-set 0 materially overstates booked profit — but it is
     * the documented behaviour of the `??` operator, not a bug to fix here.
     */
    public function test_null_fee_bps_falls_back_but_zero_fee_bps_is_taken_literally(): void
    {
        config(['trading.exchange.fee_bps' => 50]);
        $bot = BotConfigFactory::new()->create();

        // --- fee_bps = null (in-memory; the DECIMAL column is NOT NULL so this
        //     branch is only reachable via the relation object itself) ---------
        [$buyN, $sellN] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');
        $botNull = BotConfigFactory::new()->make();
        $botNull->fee_bps = null;
        $buyN->setRelation('botConfig', $botNull);

        $tradeNull = CompletedTrade::createFromOrders($buyN, $sellN)->fresh();
        // Fell back to config 50 bps → 985,000, exactly like an absent relation.
        $this->assertSame('985000.00000000', $tradeNull->fee);

        // --- fee_bps = 0 -------------------------------------------------------
        [$buyZ, $sellZ] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');
        $botZero = BotConfigFactory::new()->make(['fee_bps' => 0]);
        $buyZ->setRelation('botConfig', $botZero);

        $tradeZero = CompletedTrade::createFromOrders($buyZ, $sellZ)->fresh();
        // Zero fee — config (50) NOT consulted; net equals gross.
        $this->assertSame('0.00000000', $tradeZero->fee);
        $this->assertSame('1000000.00000000', $tradeZero->gross_profit);
        $this->assertSame('1000000.00000000', $tradeZero->net_profit);
        $this->assertSame('1000000.00000000', $tradeZero->profit);
    }

    /**
     * The zero-notional guard: when buyNotional == 0 the percentage branch must
     * return "0" instead of throwing DivisionByZeroError.
     *
     * A zero buyNotional needs buyPrice == 0 OR amount == 0. GridOrder::saving()
     * REJECTS a price <= 0, so a zero price is unreachable through the model; the
     * only reachable trigger is amount == 0 (there is no amount guard). We book a
     * pair with amount 0 and confirm every derived figure is zero and no
     * exception is raised.
     */
    public function test_zero_notional_amount_does_not_divide_by_zero(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0');

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // Guard hit: profit_percentage forced to "0" (decimal:4 → "0.0000").
        $this->assertSame('0.0000', $trade->profit_percentage);
        $this->assertSame('0.00000000', $trade->amount);
        $this->assertSame('0.00000000', $trade->gross_profit);
        $this->assertSame('0.00000000', $trade->fee);
        $this->assertSame('0.00000000', $trade->net_profit);
        $this->assertSame('0.00000000', $trade->profit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The net-vs-gross asymmetry
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * profit and net_profit are both written from netProfit, while
     * profit_percentage is derived from GROSS. This pins that asymmetry.
     *
     * Uses a fee-heavy fractional case so gross and net are far apart:
     *   buyPrice = 98,123,456,789   sellPrice = 99,123,456,789
     *   amount   = 0.00016841       fee_bps   = 35
     *   gross    = (sell − buy) × amount   = 1,000,000,000 × 0.00016841 = 168,410
     *   buyNot   = 98,123,456,789 × 0.00016841       = 16,524,971.35783549
     *   net      = 168,410 − 35bps×(buyNot+sellNot)  =     52,145.76549515157
     *
     *   pct FROM GROSS = 168,410 / 16,524,971.35783549 × 100 = 1.019124…% → "1.0191"
     *   pct FROM NET   =  52,145.76…/16,524,971.35783549 ×100 = 0.315560…% → "0.3156"
     *
     * The persisted percentage is the GROSS one. If it were (incorrectly)
     * derived from net it would read "0.3156"; we assert it does NOT.
     */
    public function test_profit_and_net_profit_use_net_while_percentage_uses_gross(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98123456789', '99123456789', '0.00016841');

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // profit & net_profit are the NET figure (decimal:8, HALF_UP).
        $this->assertSame('52145.76549515', $trade->net_profit);
        $this->assertSame('52145.76549515', $trade->profit);
        $this->assertSame('168410.00000000', $trade->gross_profit);

        // profit_percentage is GROSS-based, NOT net-based.
        $this->assertSame('1.0191', $trade->profit_percentage);
        $this->assertNotSame('0.3156', $trade->profit_percentage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // decimal(20,0) `profit` vs decimal:8 read cast — the discrepancy, pinned
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The migration declares `profit` as DECIMAL(20,0) (zero scale) while the
     * model casts it to decimal:8 on read; `net_profit` is genuinely
     * DECIMAL(20,8). Both columns receive the SAME netProfit value. What actually
     * lands in each?
     *
     * The fractional netProfit here is 52145.76549515157 (fee 35bps does not
     * divide the notionals evenly).
     *
     * WHAT SQLITE DOES (observed): sqlite's NUMERIC affinity ignores the (20,0)
     * declared scale and stores the full fractional double in `profit`. So the
     * raw `profit` and raw `net_profit` columns are byte-for-byte the same value
     * (52145.76549515157), and both read back through the model as
     * "52145.76549515". They AGREE.
     *
     * CHARACTERIZATION — SQLITE CANNOT PROVE THE PRODUCTION BEHAVIOUR HERE.
     * Under MySQL, DECIMAL(20,0) would ROUND netProfit to zero decimals on
     * INSERT: 52145.76549515157 → 52146 (HALF_UP). Then:
     *   • raw `profit`   = 52146              (integer, rounded)
     *   • raw `net_profit` = 52145.76549515157 (full scale)
     *   → the two columns DIVERGE by ~0.2345 IRT.
     *   • the model's decimal:8 read cast on `profit` would surface
     *     "52146.00000000" — i.e. the decimal:8 cast is cosmetic on a column
     *     that physically cannot hold a fraction; it can only ever append
     *     ".00000000" to an already-rounded integer.
     * The divergence is bounded by 0.5 IRT (max HALF_UP rounding delta) — on a
     * ~5.2e4 IRT profit that is < 0.001%, economically negligible, but it means
     * `profit` and `net_profit`, documented to be equal, are NOT equal under the
     * production engine, and `profit`'s decimal:8 cast is a latent lie. This is
     * exactly the kind of finding that justifies revisiting the MySQL decision.
     */
    public function test_profit_column_agrees_with_net_profit_under_sqlite_only(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98123456789', '99123456789', '0.00016841');

        $trade = CompletedTrade::createFromOrders($buy, $sell);

        // Raw column values (no cast). PDO sqlite returns these DECIMAL columns
        // as PHP floats; the exact stored double is 52145.76549515157 (at
        // serialize_precision), but a (string) cast renders it "52145.765495152"
        // under PHP's precision=14 — the very output-rounding trap that must not
        // drive an assertion. So we compare the doubles directly and bound the
        // value, never stringify it.
        $raw = DB::table('completed_trades')->where('id', $trade->id)->first();

        // CHARACTERIZATION: the two columns hold the byte-for-byte SAME double —
        // sqlite ignored `profit`'s DECIMAL(20,0) zero-scale and kept the
        // fraction, so it did not diverge from net_profit's DECIMAL(20,8).
        $this->assertSame($raw->net_profit, $raw->profit);

        // Proof sqlite preserved a FRACTION (did not round to an integer): the
        // value sits strictly between 52145 and 52146. MySQL's DECIMAL(20,0)
        // would instead have stored exactly 52146.0 in `profit` (HALF_UP),
        // diverging from net_profit by ~0.2345 IRT — sqlite cannot reproduce
        // that, so this green does NOT prove the production behaviour.
        $this->assertGreaterThan(52145.0, (float) $raw->profit);
        $this->assertLessThan(52146.0, (float) $raw->profit);

        // Through the model's casts they also agree — again, only because sqlite
        // did not truncate. Do NOT read this green as proof of production parity.
        $fresh = $trade->fresh();
        $this->assertSame('52145.76549515', $fresh->profit);
        $this->assertSame('52145.76549515', $fresh->net_profit);
        $this->assertSame($fresh->net_profit, $fresh->profit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // amount = min(both legs)
    // ─────────────────────────────────────────────────────────────────────────

    /** Equal legs: booked amount is exactly that shared quantity, and the
     *  unequal-leg warning is NOT emitted. */
    public function test_amount_for_equal_legs_is_that_quantity_and_does_not_warn(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        $logger = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('trading')->andReturn($logger);

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('0.00100000', $trade->amount);
        $logger->shouldNotHaveReceived('warning');
    }

    /**
     * Unequal legs, constructed the way production actually produces them: the
     * buy fills only partially (requested 0.001 BTC, filled_amount 0.0005), and
     * CheckTradesJob then sizes the continuation SELL from that filled_amount, so
     * the sell's `amount` is 0.0005.
     *
     * IMPLICIT COUPLING PINNED: CheckTradesJob sizes the pair from the parent's
     * filled_amount, but createFromOrders books min() of the two orders' `amount`
     * columns. With buy.amount = 0.001 and sell.amount = 0.0005, the min is
     * 0.0005 — which equals the matched (filled) quantity. So the two independent
     * sizing rules coincide, and the trade is booked on the real matched size.
     * The unequal-leg warning path fires because buy.amount != sell.amount.
     */
    public function test_amount_for_unequal_legs_is_the_smaller_and_warns(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        // buy requested 0.001, only 0.0005 filled; sell sized from filled_amount.
        [$buy, $sell] = $this->filledPair(
            $bot->id,
            '98000000000',
            '99000000000',
            buyAmount: '0.00100000',
            sellAmount: '0.00050000',
            buyFilledAmount: '0.00050000',
        );

        $logger = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('trading')->andReturn($logger);

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // Booked on the matched quantity = min(0.001, 0.0005) = 0.0005.
        $this->assertSame('0.00050000', $trade->amount);

        // The unequal-leg warning path was exercised.
        $logger->shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context = []) {
                return $message === 'COMPLETED_TRADE_UNEQUAL_LEG_AMOUNTS'
                    && ($context['matched_amount'] ?? null) === '0.00050000';
            });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Non-determinism: execution time, and the cache-driven market snapshot
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CHARACTERIZATION — execution_time_seconds is the NEGATION of the real
     * elapsed time. It is computed as
     *   $sellOrder->updated_at->diffInSeconds($buyOrder->created_at)
     * and this project runs Carbon 3 (nesbot/carbon 3.10.3), whose diff methods
     * are SIGNED by default. Calling `$later->diffInSeconds($earlier)` therefore
     * yields a negative number: here the sell is updated 120s after the buy is
     * created, and the booked duration is -120, not +120. (Carbon 2 returned the
     * absolute value; the code reads as if it still does.)
     *
     * MAGNITUDE / BLAST RADIUS: this bites EVERY normal round-trip (sell always
     * completes after the buy), so 100% of booked trades store a negative
     * duration. getExecutionTimeFormattedAttribute() then formats it as e.g.
     * "-2 دقیقه، 0 ثانیه", and any avg/sum over execution_time_seconds is
     * sign-flipped. This is locked, not fixed.
     */
    public function test_execution_time_seconds_is_negated_elapsed_time(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        // buy created 00:00:00, sell updated 00:02:00 → 120s elapsed.
        [$buy, $sell] = $this->filledPair(
            $bot->id,
            '98000000000',
            '99000000000',
            '0.00100000',
            buyCreatedAt: '2026-01-01 00:00:00',
            sellUpdatedAt: '2026-01-01 00:02:00',
        );

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // CHARACTERIZATION: negative, because Carbon 3 diffInSeconds is signed
        // and the receiver (sell.updated_at) is LATER than the argument
        // (buy.created_at).
        $this->assertSame(-120, $trade->execution_time_seconds);
    }

    /**
     * With a COLD cache (the normal production state right after a restart),
     * market_conditions records a null btc price and detectMarketTrend() returns
     * 'sideways' (0 vs 0 is neither >2% up nor <2% down). The timestamp is the
     * pinned now().
     */
    public function test_market_conditions_with_cold_cache(): void
    {
        Cache::flush(); // no btc_price / btc_price_1h_ago

        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // Timestamp is derived from the same frozen clock the code reads
        // (now()->toISOString()), so the assertion stays timezone-independent.
        $this->assertSame([
            'btc_price_at_trade' => null,
            'timestamp'          => now()->toISOString(),
            'trend'              => 'sideways',
        ], $trade->market_conditions);
    }

    /**
     * With the cache seeded, btc_price_at_trade is captured verbatim and
     * detectMarketTrend() classifies the move. 100e9 vs 98e9 is +2.04%, above
     * the +2% bullish threshold (98e9 × 1.02 = 99.96e9 < 100e9).
     */
    public function test_market_conditions_with_seeded_cache_reports_the_trend(): void
    {
        Cache::put('btc_price', 100_000_000_000);
        Cache::put('btc_price_1h_ago', 98_000_000_000);

        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // Timestamp is derived from the same frozen clock the code reads
        // (now()->toISOString()), so the assertion stays timezone-independent.
        $this->assertSame([
            'btc_price_at_trade' => 100_000_000_000,
            'timestamp'          => now()->toISOString(),
            'trend'              => 'bullish',
        ], $trade->market_conditions);
    }
}
