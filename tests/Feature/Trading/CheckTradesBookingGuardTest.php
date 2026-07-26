<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\BotActivityLogger;
use App\Services\NobitexService;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 13 — Step 8: characterization tests for the BOOKING GUARD,
 * {@see CheckTradesJob::createCompletedTradeIfPaired()} (private; around
 * app/Jobs/CheckTradesJob.php:591). This is the single gate that decides
 * whether a filled leg becomes a persisted {@see CompletedTrade}. Step 7 pinned
 * the arithmetic of CompletedTrade::createFromOrders; this step pins the
 * DECISION LOGIC that precedes it — the paired_order_id link resolution and
 * every early-return guard.
 *
 * DISCIPLINE (same as Steps 1-7): these tests lock the ACTUAL behaviour. Where
 * a behaviour is surprising it is marked `// CHARACTERIZATION:` and reported; no
 * assertion is weakened to make production look correct, and no production file,
 * migration, or config is touched. No delta matching — every count and every id
 * is asserted exactly.
 *
 * INVARIANTS UNDER TEST (roadmap product rules the guard must enforce):
 *   INV-5  profit is booked ONLY when BOTH legs of a cycle are filled.
 *   INV-6  in every booked cycle, sell price > buy price, regardless of which
 *          leg filled first (buy/sell roles assigned by TYPE, never fill order).
 *   INV-7  one filled order must NEVER produce two CompletedTrades.
 *
 * DRIVING A PRIVATE METHOD: createCompletedTradeIfPaired() is private and the
 * job needs no constructor args, so it is invoked via reflection with a
 * hand-built (order, bot) pair — the same technique CheckTradesPairOrderTest
 * uses for createPairOrderLocked().
 *
 * NO EXCHANGE CONTACT: the booking path resolves exactly one collaborator via
 * the container — app(BotActivityLogger::class), called twice (once in
 * createCompletedTradeIfPaired for logOrderPaired, once inside
 * recordCompletedTrade for logTradeCompleted). It never resolves
 * NobitexService. setUp() binds a tolerant BotActivityLogger mock (so the DB
 * has no bot_activity_logs dependency and any logger signature is accepted) and
 * a STRICT NobitexService mock with zero allowed methods — any call to it would
 * throw — plus Http::fake(). A green run therefore PROVES no real exchange call
 * happens on the booking path.
 *
 * DETERMINISM: createFromOrders reads now(), cache('btc_price') and the two
 * trend cache keys. The clock is frozen at an EXPLICIT UTC instant (never a
 * naive local-timezone literal — the host is Asia/Tehran, the container UTC),
 * and the cache is flushed cold in setUp.
 *
 * MAGNITUDE: realistic BTCIRT — prices ~9.8e10 IRT/BTC, amounts 1e-3 BTC,
 * notionals ~1e8 IRT. Everything is ~11 orders of magnitude below Money's
 * 10^(14-scale) float-cast cliffs, and the inputs are integer/decimal strings
 * so the %.20F float path is never exercised.
 */
final class CheckTradesBookingGuardTest extends TestCase
{
    use BuildsGridSchema;

    /** Fixed wall-clock so now()/toISOString() inside createFromOrders is pinned. */
    private const NOW = '2026-01-01 00:00:00';

    private const BUY_PRICE  = '98000000000';
    private const SELL_PRICE = '99000000000';
    private const AMOUNT     = '0.00100000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();

        // Freeze at an explicit UTC instant, not a naive literal parsed in the
        // app's local timezone. createFromOrders serialises now()->toISOString()
        // in UTC; a local-timezone freeze would make the booked timestamp
        // host-dependent (3.5h off under Asia/Tehran) — exactly the Step 7 trap.
        \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse(self::NOW, 'UTC'));

        // Cold cache: detectMarketTrend() and btc_price_at_trade read these keys.
        Cache::flush();

        // The booking path calls app(BotActivityLogger::class) twice. A mock that
        // ignores missing methods tolerates logOrderPaired(...) and
        // logTradeCompleted(...) with any args and returns null — so the test
        // needs no bot_activity_logs writes and never asserts on logging.
        $this->app->instance(
            BotActivityLogger::class,
            Mockery::mock(BotActivityLogger::class)->shouldIgnoreMissing()
        );

        // The booking path must NEVER touch the exchange. Bind a STRICT mock (no
        // allowed methods): if the method ever resolved NobitexService and called
        // anything on it, the test would error with a BadMethodCallException.
        $this->app->instance(NobitexService::class, Mockery::mock(NobitexService::class));

        // Belt-and-suspenders: even a real service could not reach the network.
        Http::fake();
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    private function bot(): BotConfig
    {
        // fee_bps 35 is the factory default; the exact profit numbers are Step 7's
        // concern — here we only care WHETHER a trade is booked, not its columns.
        return BotConfigFactory::new()->create(['fee_bps' => 35]);
    }

    private function filledBuy(BotConfig $bot, string $price = self::BUY_PRICE): GridOrder
    {
        return GridOrderFactory::new()->buy()->filled()->create([
            'bot_config_id' => $bot->id,
            'price'         => $price,
            'amount'        => self::AMOUNT,
        ]);
    }

    private function filledSell(BotConfig $bot, string $price = self::SELL_PRICE): GridOrder
    {
        return GridOrderFactory::new()->sell()->filled()->create([
            'bot_config_id' => $bot->id,
            'price'         => $price,
            'amount'        => self::AMOUNT,
        ]);
    }

    /** Invoke the private booking guard by reflection, as the job would per fill. */
    private function book(GridOrder $order, BotConfig $bot): void
    {
        $job = new CheckTradesJob();
        $ref = new ReflectionMethod($job, 'createCompletedTradeIfPaired');
        $ref->setAccessible(true);
        $ref->invoke($job, $order, $bot);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // INV-5 — profit is booked ONLY when BOTH legs are filled
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * A filled order with paired_order_id === null has no continuation partner
     * yet (an initial-grid fill whose partner createPairOrder() has not spawned).
     * Guard 1 (`$order->paired_order_id === null`) returns early: nothing booked,
     * no error.
     */
    public function test_inv5_filled_order_with_no_partner_books_nothing(): void
    {
        $bot = $this->bot();
        $buy = $this->filledBuy($bot); // paired_order_id defaults to null

        $this->assertNull($buy->paired_order_id, 'Pre-condition: the fill has no continuation partner.');

        $this->book($buy, $bot);

        $this->assertSame(0, CompletedTrade::count(), 'A single filled leg with no partner must not book a trade.');
    }

    /**
     * INV-5 core: one leg filled, partner LINKED but still 'placed' (not filled).
     * Guard 3 (`$partner->status !== 'filled'`) defers — the trade will book
     * later, when the partner's own fill is processed. Nothing booked now.
     */
    public function test_inv5_filled_leg_with_unfilled_partner_defers(): void
    {
        $bot = $this->bot();

        $buy  = $this->filledBuy($bot);
        $sell = GridOrderFactory::new()->sell()->placed()->create([
            'bot_config_id' => $bot->id,
            'price'         => self::SELL_PRICE,
            'amount'        => self::AMOUNT,
        ]);
        GridOrderFactory::linkPair($buy, $sell);

        $this->assertSame('placed', $sell->fresh()->status, 'Pre-condition: partner leg is not filled.');

        $this->book($buy->fresh(), $bot);

        $this->assertSame(
            0,
            CompletedTrade::count(),
            'INV-5: a single filled leg (partner not yet filled) must NOT create a CompletedTrade.'
        );
    }

    /**
     * INV-5 satisfied: both legs filled and bidirectionally linked → exactly one
     * CompletedTrade, and it references the two orders by their true sides.
     */
    public function test_inv5_both_legs_filled_and_linked_books_exactly_one(): void
    {
        $bot = $this->bot();

        $buy  = $this->filledBuy($bot);
        $sell = $this->filledSell($bot);
        GridOrderFactory::linkPair($buy, $sell);

        $this->book($buy->fresh(), $bot);

        $this->assertSame(1, CompletedTrade::count(), 'Both legs filled and linked must book exactly one trade.');

        $trade = CompletedTrade::firstOrFail();
        $this->assertSame($buy->id, $trade->buy_order_id);
        $this->assertSame($sell->id, $trade->sell_order_id);

        // And no exchange call happened while booking it.
        Http::assertNothingSent();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // INV-6 — sell price > buy price, roles assigned by TYPE not by fill order
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * buy-then-sell: the BUY filled first, the SELL is the continuation that
     * fills later, and the guard runs on the SELL's processing (we pass the sell
     * as $order). The role split
     *   [$buyOrder, $sellOrder] = $order->type === 'buy' ? [$order, $partner] : [$partner, $order];
     * puts the buy-type order in buy_order_id even though $order is the sell.
     */
    public function test_inv6_buy_then_sell_assigns_roles_by_type(): void
    {
        $bot = $this->bot();

        $buy  = $this->filledBuy($bot,  self::BUY_PRICE);
        $sell = $this->filledSell($bot, self::SELL_PRICE);
        GridOrderFactory::linkPair($buy, $sell);

        // Book on the SELL's fill (the continuation leg).
        $this->book($sell->fresh(), $bot);

        $trade = CompletedTrade::firstOrFail();
        $this->assertSame($buy->id,  $trade->buy_order_id,  'The buy-type leg must land in buy_order_id.');
        $this->assertSame($sell->id, $trade->sell_order_id, 'The sell-type leg must land in sell_order_id.');

        // INV-6: sell price strictly greater than buy price. bccomp is exact on
        // the decimal:8 strings; a native (string) cast would round at
        // precision=14 and must not drive the assertion.
        $this->assertSame('98000000000.00000000', $trade->buy_price);
        $this->assertSame('99000000000.00000000', $trade->sell_price);
        $this->assertSame(1, bccomp($trade->sell_price, $trade->buy_price, 8), 'INV-6: sell_price > buy_price.');
    }

    /**
     * sell-then-buy: the SELL filled first, the BUY is the continuation that
     * fills later, and the guard runs on the BUY's processing (we pass the buy as
     * $order). This is the case most likely to expose a role-assignment bug — the
     * buy leg filled SECOND, yet it must still occupy buy_order_id, and
     * sell_price must still exceed buy_price.
     *
     * CHARACTERIZATION: the roles are assigned purely from order->type, never
     * from fill order or created_at, so the guard is symmetric — booking on
     * either leg produces the identical (buy_order_id, sell_order_id) mapping.
     */
    public function test_inv6_sell_then_buy_still_puts_buy_leg_in_buy_role(): void
    {
        $bot = $this->bot();

        // Sell exists first (filled earlier); buy is the later continuation fill.
        $sell = $this->filledSell($bot, self::SELL_PRICE);
        $buy  = $this->filledBuy($bot,  self::BUY_PRICE);
        GridOrderFactory::linkPair($sell, $buy);

        // Book on the BUY's fill — the leg that filled SECOND.
        $this->book($buy->fresh(), $bot);

        $trade = CompletedTrade::firstOrFail();
        $this->assertSame(
            $buy->id,
            $trade->buy_order_id,
            'INV-6: the buy-type leg must be in buy_order_id even though it filled second.'
        );
        $this->assertSame($sell->id, $trade->sell_order_id);

        $this->assertSame('98000000000.00000000', $trade->buy_price);
        $this->assertSame('99000000000.00000000', $trade->sell_price);
        $this->assertSame(1, bccomp($trade->sell_price, $trade->buy_price, 8), 'INV-6: sell_price > buy_price.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // INV-7 — one filled order must NEVER produce two CompletedTrades
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Re-run the booking path TWICE for the same filled+linked pair (a queue
     * retry or a repeated scheduler tick on the same fill). The double-booking
     * guard — CompletedTrade::where(buy_order_id, sell_order_id)->exists() —
     * makes the second call a no-op. Exactly one trade.
     */
    public function test_inv7_re_running_the_same_pair_books_once(): void
    {
        $bot = $this->bot();

        $buy  = $this->filledBuy($bot);
        $sell = $this->filledSell($bot);
        GridOrderFactory::linkPair($buy, $sell);

        $this->book($buy->fresh(), $bot);
        $this->book($buy->fresh(), $bot); // re-run

        $this->assertSame(1, CompletedTrade::count(), 'INV-7: re-running the booking path must not double-book.');
    }

    /**
     * Process BOTH legs — the guard fires once per leg as the job handles each
     * fill (first the buy, then the sell). The first call books; the second finds
     * the already-booked row and skips. Still exactly one trade — and it is the
     * SAME trade, keyed on the same (buy, sell) pair regardless of entry leg.
     */
    public function test_inv7_processing_both_legs_books_once(): void
    {
        $bot = $this->bot();

        $buy  = $this->filledBuy($bot);
        $sell = $this->filledSell($bot);
        GridOrderFactory::linkPair($buy, $sell);

        $this->book($buy->fresh(), $bot);  // enter via the buy leg → books
        $this->book($sell->fresh(), $bot); // enter via the sell leg → already booked

        $this->assertSame(1, CompletedTrade::count(), 'INV-7: processing both legs must book exactly once.');

        $trade = CompletedTrade::firstOrFail();
        $this->assertSame($buy->id, $trade->buy_order_id);
        $this->assertSame($sell->id, $trade->sell_order_id);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Guard edge cases — every early return in the method
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Guard 2 (`if (!$partner)`): paired_order_id points to an order that no
     * longer exists (partner deleted). The scoped lookup returns null, the method
     * logs a warning and returns — no booking, no error, no exception.
     */
    public function test_guard_missing_partner_is_skipped_without_error(): void
    {
        $bot = $this->bot();

        $buy  = $this->filledBuy($bot);
        $sell = $this->filledSell($bot);
        GridOrderFactory::linkPair($buy, $sell);

        // The continuation partner vanishes, but the fill still records the link.
        $deletedPartnerId = $sell->id;
        $sell->delete();
        $buy->refresh();
        $this->assertSame($deletedPartnerId, $buy->paired_order_id, 'Pre-condition: the fill still links to the now-deleted partner.');

        $this->book($buy, $bot);

        $this->assertSame(0, CompletedTrade::count(), 'A dangling partner link must be skipped, not booked.');
    }

    /**
     * Guard 4 (`if ($order->type === $partner->type)`): the two LINKED orders are
     * the SAME side (both buy). A same-side pair cannot form a buy/sell
     * round-trip, so the method logs a warning and refuses to book.
     *
     * CHARACTERIZATION: the guard keys purely on `type` equality. Two filled buys
     * that are (incorrectly) linked never book — the method does not attempt to
     * coerce one into a sell role — so a corrupt same-side link fails safe (no
     * bogus profit) rather than fabricating a nonsensical trade.
     */
    public function test_guard_same_side_pair_refuses_to_book(): void
    {
        $bot = $this->bot();

        $buyA = $this->filledBuy($bot, self::BUY_PRICE);
        $buyB = $this->filledBuy($bot, self::SELL_PRICE); // still a BUY, just a different price
        GridOrderFactory::linkPair($buyA, $buyB);

        $this->assertSame('buy', $buyA->fresh()->type);
        $this->assertSame('buy', $buyB->fresh()->type);

        $this->book($buyA->fresh(), $bot);

        $this->assertSame(
            0,
            CompletedTrade::count(),
            'Two same-side (both buy) linked orders must never book a completed trade.'
        );
    }

    /**
     * The symmetric same-side case: two linked filled SELLS. Same guard, same
     * refusal — pinned so the guard is proven independent of which side the
     * duplicated type is.
     */
    public function test_guard_same_side_sell_pair_also_refuses(): void
    {
        $bot = $this->bot();

        $sellA = $this->filledSell($bot, self::SELL_PRICE);
        $sellB = $this->filledSell($bot, self::BUY_PRICE);
        GridOrderFactory::linkPair($sellA, $sellB);

        $this->book($sellA->fresh(), $bot);

        $this->assertSame(0, CompletedTrade::count(), 'Two same-side (both sell) linked orders must never book.');
    }
}
