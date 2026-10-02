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

        // Fee model Phase 1: rates come from FeeModel (bot buy_fee_bps /
        // sell_fee_bps override → config trading.fees.*), never from the legacy
        // fee_bps column. The characterization figures in this file were derived
        // at 35 bps on both legs, so pin the config there; tests that need other
        // rates override it.
        config(['trading.fees.buy_fee_bps' => '35', 'trading.fees.sell_fee_bps' => '35']);
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
     * Clean winning round-trip with EXPLICIT per-side bot overrides read straight
     * off the buy order's bot (buy_fee_bps = sell_fee_bps = 20, distinguishable
     * from the pinned config of 35). The legacy fee_bps column is set to a
     * different value (99) to prove it is NOT read. Hand-derived oracle:
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
    public function test_books_exact_values_for_a_clean_pair_with_explicit_bot_fee_override(): void
    {
        $bot = BotConfigFactory::new()->live()->create(['fee_bps' => 99, 'buy_fee_bps' => 20, 'sell_fee_bps' => 20]);
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

        // profit and net_profit are both written from the NET figure. profit is
        // cast decimal:0 (matching its DECIMAL(20,0) column), so it reads back as
        // a whole rial; net_profit keeps its decimal:8 form. For a net that is
        // already integral (606,000) the two only differ in trailing zeros.
        $this->assertSame('606000', $trade->profit);
        $this->assertSame('606000.00000000', $trade->net_profit);

        // profit_percentage is GROSS/buyNotional×100, read back through the
        // model's decimal:4 cast (HALF_UP): 1.020408163… → "1.0204".
        $this->assertSame('1.0204', $trade->profit_percentage);

        $this->assertSame('grid', $trade->trade_type);
        $this->assertNull($trade->grid_level_buy);
        $this->assertNull($trade->grid_level_sell);
    }

    /**
     * The fee rates fall back to config('trading.fees.*_fee_bps') when the buy
     * order has no bot to read overrides from. The config values are set to 50
     * (0.5%) here so the fallback is PROVABLE: a 50-bps fee is distinct from
     * every other rate this file uses.
     *
     *   feeRate = 50/10000 = 0.005
     *   fee     = 0.005 × 197,000,000 = 985,000
     *   net     = 1,000,000 − 985,000 = 15,000
     */
    public function test_fee_rate_falls_back_to_config_when_bot_config_is_absent(): void
    {
        config(['trading.fees.buy_fee_bps' => '50', 'trading.fees.sell_fee_bps' => '50']);

        $bot = BotConfigFactory::new()->create(['buy_fee_bps' => 20, 'sell_fee_bps' => 20]);
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        // No bot on either leg to read overrides from → FeeModel uses the
        // config rates. (Booking falls back from the buy leg's bot to the
        // sell leg's — both legs always belong to the same bot.)
        $buy->setRelation('botConfig', null);
        $sell->setRelation('botConfig', null);

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('985000.00000000', $trade->fee);
        $this->assertSame('15000.00000000', $trade->net_profit);
        $this->assertSame('15000', $trade->profit); // decimal:0 cast → whole rial
    }

    /**
     * FeeModel distinguishes a NULL override from a zero override:
     *   - buy/sell_fee_bps = null → not overridden → config (here 50 bps).
     *   - buy/sell_fee_bps = 0    → taken literally → zero fee, net == gross.
     *
     * A deliberate 0 override means a zero-fee bot; the bot form exposes the
     * override fields with "blank = default" so NULL is the normal state.
     */
    public function test_null_override_falls_back_but_zero_override_is_taken_literally(): void
    {
        config(['trading.fees.buy_fee_bps' => '50', 'trading.fees.sell_fee_bps' => '50']);
        $bot = BotConfigFactory::new()->create();

        // --- fee_bps = null (in-memory; the DECIMAL column is NOT NULL so this
        //     branch is only reachable via the relation object itself) ---------
        [$buyN, $sellN] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');
        $botNull = BotConfigFactory::new()->make(['buy_fee_bps' => null, 'sell_fee_bps' => null]);
        $buyN->setRelation('botConfig', $botNull);

        $tradeNull = CompletedTrade::createFromOrders($buyN, $sellN)->fresh();
        // Fell back to config 50 bps → 985,000, exactly like an absent relation.
        $this->assertSame('985000.00000000', $tradeNull->fee);

        // --- fee_bps = 0 -------------------------------------------------------
        [$buyZ, $sellZ] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');
        $botZero = BotConfigFactory::new()->make(['buy_fee_bps' => 0, 'sell_fee_bps' => 0]);
        $buyZ->setRelation('botConfig', $botZero);

        $tradeZero = CompletedTrade::createFromOrders($buyZ, $sellZ)->fresh();
        // Zero override — config (50) NOT consulted; net equals gross.
        $this->assertSame('0.00000000', $tradeZero->fee);
        $this->assertSame('1000000.00000000', $tradeZero->gross_profit);
        $this->assertSame('1000000.00000000', $tradeZero->net_profit);
        $this->assertSame('1000000', $tradeZero->profit); // decimal:0 cast → whole rial
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
        $this->assertSame('0', $trade->profit); // decimal:0 cast → whole rial
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

        // Both are written from the NET figure, but read back through different
        // casts: net_profit is decimal:8 (keeps the fraction); profit is decimal:0
        // (matching its DECIMAL(20,0) column, HALF_UP → whole rial). 52145.765… ⇒
        // net_profit "52145.76549515", profit "52146".
        $this->assertSame('52145.76549515', $trade->net_profit);
        $this->assertSame('52146', $trade->profit);
        $this->assertSame('168410.00000000', $trade->gross_profit);

        // profit_percentage is GROSS-based, NOT net-based.
        $this->assertSame('1.0191', $trade->profit_percentage);
        $this->assertNotSame('0.3156', $trade->profit_percentage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // decimal(20,0) `profit` vs its read cast — the cast now matches the column
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CHARACTERIZATION — column-vs-cast history.
     *
     * The migration declares `profit` as DECIMAL(20,0) (ZERO decimal places)
     * while `net_profit` is genuinely DECIMAL(20,8). createFromOrders writes the
     * SAME netProfit into both. Originally the model cast `profit` to 'decimal:8',
     * which LIED about the column: the DECIMAL(20,0) storage physically cannot
     * hold a fraction, yet the read cast pretended 8 places existed and appended
     * ".00000000" to an already-rounded integer. Phase-2 cleanup changed the cast
     * to 'decimal:0' so it tells the truth about the column. This test pins that
     * the cast now matches the column while net_profit keeps the real fraction.
     *
     * The fractional netProfit here is 52145.76549515157 (fee 35 bps does not
     * divide the notionals evenly).
     *
     * WHAT SQLITE DOES (observed): sqlite's NUMERIC affinity ignores the (20,0)
     * declared scale and stores the full fractional double in the raw `profit`
     * column — so the RAW `profit` and RAW `net_profit` columns are still
     * byte-for-byte the same double. The rounding to whole rial now happens on
     * READ, in the decimal:0 cast (BigDecimal::toScale(0, HALF_UP)), so the model
     * surfaces "52146" — exactly the whole-rial value MySQL's DECIMAL(20,0)
     * column would have physically stored on INSERT. The decimal:0 read cast thus
     * reconciles the sqlite test engine with the MySQL production engine: both
     * present `profit` as a rounded whole rial, diverging from net_profit's
     * fractional value by < 0.5 IRT (cosmetic on IRT, a whole-rial currency).
     */
    public function test_profit_read_cast_rounds_to_whole_rial_matching_its_column(): void
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

        // CHARACTERIZATION: the two RAW columns still hold the byte-for-byte SAME
        // double — sqlite ignored `profit`'s DECIMAL(20,0) zero-scale on write and
        // kept the fraction, so the divergence is not visible at the storage layer
        // under sqlite (it would be under MySQL, which rounds on INSERT).
        $this->assertSame($raw->net_profit, $raw->profit);

        // Proof sqlite preserved a FRACTION on write (did not round to an
        // integer): the raw value sits strictly between 52145 and 52146.
        $this->assertGreaterThan(52145.0, (float) $raw->profit);
        $this->assertLessThan(52146.0, (float) $raw->profit);

        // Through the model's casts they now DIVERGE: the decimal:0 cast rounds
        // profit to the whole rial "52146" (matching what DECIMAL(20,0) stores),
        // while net_profit's decimal:8 cast keeps the fraction. The cast no longer
        // lies about the column.
        $fresh = $trade->fresh();
        $this->assertSame('52146', $fresh->profit);
        $this->assertSame('52145.76549515', $fresh->net_profit);
        $this->assertNotSame($fresh->net_profit, $fresh->profit);
    }

    /**
     * CHARACTERIZATION — the Phase-2 cast fix, stated as its own contract.
     *
     * Books a trade whose netProfit has a genuine fractional part (fee_bps = 35
     * does not divide the notionals evenly, giving 52145.76549515157 IRT), then
     * asserts the two columns that both receive that value read back per their
     * ACTUAL types:
     *   • profit     — DECIMAL(20,0) column, now cast 'decimal:0' → "52146"
     *                  (whole rial, HALF_UP), matching the storage that cannot
     *                  hold a fraction.
     *   • net_profit — DECIMAL(20,8) column, cast 'decimal:8' → "52145.76549515"
     *                  (fraction preserved, unchanged by this fix).
     *
     * Before the fix `profit` was cast 'decimal:8' and would have read back
     * "52145.76549515" — a fraction the DECIMAL(20,0) column can never actually
     * store. The delta between the two figures is < 0.5 IRT and IRT is a
     * whole-rial currency, so this is cosmetic; the fix is about the cast matching
     * the column, not about changing any economic value.
     */
    public function test_fractional_net_reads_as_whole_rial_profit_but_precise_net_profit(): void
    {
        $bot = BotConfigFactory::new()->create(['fee_bps' => 35]);
        [$buy, $sell] = $this->filledPair($bot->id, '98123456789', '99123456789', '0.00016841');

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        // profit reads back as the whole-rial value the DECIMAL(20,0) column
        // actually stores (decimal:0, HALF_UP): 52145.765… → "52146".
        $this->assertSame('52146', $trade->profit);

        // net_profit still carries the fractional value (decimal:8, unchanged).
        $this->assertSame('52145.76549515', $trade->net_profit);

        // They are genuinely different string representations now — profit no
        // longer pretends to hold decimals its column cannot store.
        $this->assertNotSame($trade->net_profit, $trade->profit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // amount = quantity round-tripped (sell leg); residual booked, not warned
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Equal legs (the OLD gross exit sizing): the cycle sold 0.001 BTC but the
     * buy only credited 0.001 − 0.0000035 (35 bps buy fee in BTC). The
     * booking records that as base_residual = −0.0000035 and — since it is a
     * whole step or more — logs COMPLETED_TRADE_BASE_RESIDUAL: this is the
     * audit D1 shortfall made visible. (Fee model Phase 6: previously equal
     * legs were silent; the shortfall was invisible.)
     */
    public function test_equal_legs_book_the_gross_sizing_shortfall_as_residual_and_warn(): void
    {
        $bot = BotConfigFactory::new()->create();
        [$buy, $sell] = $this->filledPair($bot->id, '98000000000', '99000000000', '0.00100000');

        $logger = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('trading')->andReturn($logger);

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('0.00100000', $trade->amount);
        $this->assertSame('-0.000003500000000000', $trade->base_residual);
        $logger->shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context = []) => $message === 'COMPLETED_TRADE_BASE_RESIDUAL'
                && ($context['residual'] ?? null) === '-0.0000035');
    }

    /**
     * Unequal legs as production now produces them (Phase 4 fee-net exit
     * sell): buy 0.00578704 is charged 0.00578704 × 0.0035 = 0.00002025464
     * BTC, crediting 0.00576678536; the exit sells floor8 = 0.00576678.
     * amount = the sold quantity, base_residual = the sub-step dust
     * 0.00000000536, no warning.
     */
    public function test_fee_net_exit_books_the_sold_quantity_and_sub_step_residual_without_warning(): void
    {
        $bot = BotConfigFactory::new()->create();
        [$buy, $sell] = $this->filledPair(
            $bot->id,
            '98000000000',
            '99470000000',
            buyAmount: '0.00578704',
            sellAmount: '0.00576678',
        );

        $logger = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('trading')->andReturn($logger);

        $trade = CompletedTrade::createFromOrders($buy, $sell)->fresh();

        $this->assertSame('0.00576678', $trade->amount);
        $this->assertSame('0.005787040000000000', $trade->buy_filled_amount);
        $this->assertSame('0.005766780000000000', $trade->sell_filled_amount);
        $this->assertSame('0.000000005360000000', $trade->base_residual);
        $logger->shouldNotHaveReceived('warning');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Non-determinism: execution time, and the cache-driven market snapshot
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * execution_time_seconds is the NON-NEGATIVE elapsed time from buy creation
     * to sell update. It is computed as
     *   $buyOrder->created_at->diffInSeconds($sellOrder->updated_at)
     * and this project runs Carbon 3 (nesbot/carbon 3.10.3), whose diff methods
     * are SIGNED by default. Calling `$earlier->diffInSeconds($later)` therefore
     * yields a positive number: here the sell is updated 120s after the buy is
     * created, so the booked duration is +120.
     *
     * A same-second fill (buy and sell in the same instant) yields exactly 0 —
     * not a negative and not an error.
     */
    public function test_execution_time_seconds_is_elapsed_time_and_non_negative(): void
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

        // Positive elapsed seconds: the receiver (buy.created_at) is EARLIER
        // than the argument (sell.updated_at), so Carbon 3's signed diff is +120.
        $this->assertSame(120, $trade->execution_time_seconds);

        // Same-second fill: buy and sell share the instant → exactly 0.
        [$buyFast, $sellFast] = $this->filledPair(
            $bot->id,
            '98000000000',
            '99000000000',
            '0.00100000',
            buyCreatedAt: '2026-01-01 00:00:00',
            sellUpdatedAt: '2026-01-01 00:00:00',
        );

        $fastTrade = CompletedTrade::createFromOrders($buyFast, $sellFast)->fresh();

        $this->assertSame(0, $fastTrade->execution_time_seconds);
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
