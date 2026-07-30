<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Observers\GridOrderObserver;
use App\Support\Money;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 13 — Step 10: characterization tests for
 * {@see \App\Observers\GridOrderObserver}.
 *
 * The observer is registered globally in AppServiceProvider
 * (GridOrder::observe(GridOrderObserver::class)) and fires on every GridOrder
 * save/delete. On each event it RECOMPUTES two read-only accounting columns on
 * the owning bot_config and persists them in a single update():
 *   - open_cycles_count
 *   - capital_locked_irt
 * The recompute is wrapped in try/catch(\Throwable): on failure it logs
 * GRID_ORDER_OBSERVER_RECOMPUTE_FAILED to the 'trading' channel and SWALLOWS
 * the error, so a recompute hiccup can never roll back a recorded fill. That
 * swallow is intentional and must NOT be removed — but it means a green test
 * that only asserts "no exception was thrown" would be a FALSE GREEN: a missing
 * column (the exact Step 5 failure) makes the write throw-and-swallow while the
 * column stays NULL. Every accounting test below therefore pins the concrete
 * NEW value that was actually written, proving the recompute ran to completion.
 *
 * DISCIPLINE (same as Steps 1-9): these tests lock the ACTUAL behaviour. Where a
 * behaviour is surprising it is marked `// CHARACTERIZATION:` and reported; no
 * assertion is weakened to make production look "right", no delta matching is
 * used (every count and every IRT figure is asserted exactly), and no
 * production file, migration, or config is touched.
 *
 * OBSERVER MAP (read from app/Observers/GridOrderObserver.php, not assumed):
 *   • open_cycles_count  = COUNT of GridOrder where bot_config_id = bot AND
 *     role = 'cycle_exit' AND status = 'placed'. BOTH sides count — a buy-side
 *     and a sell-side cycle_exit at 'placed' each add 1.
 *   • capital_locked_irt = sum, over the SELL-side open cycles only
 *     (role='cycle_exit', status='placed', type='sell', paired_order_id NOT
 *     NULL), of Money::mul(paired->price, paired->amount) — i.e. the notional of
 *     the order pointed at by paired_order_id, NOT the sell's own price/amount —
 *     then floored to integer IRT via Money::add(sum, '0', 0). Buy-side open
 *     cycles lock nothing.
 *   • Lifecycle: recomputes on saved() (Eloquent "saved" = insert + update) and
 *     on deleted(). There is no created()/updated()/restored() hook — saved()
 *     covers create+update, and the model is not soft-deletable.
 *   • It recomputes for the ONE bot that owns the changed order, and does so by
 *     full re-aggregation (idempotent), not by incrementing a counter.
 *   • It does NOT gate on the simulation flag — simulation bots recompute too.
 *
 * MAGNITUDE / PRECISION: realistic BTCIRT — prices ~9.8e10 IRT/BTC, amounts
 * 1e-4..1e-2 BTC, locked notionals ~1e7..1e9 IRT. Those results are ~10 orders
 * of magnitude below both Money's 10^(14-scale) float-cast cliffs and sqlite's
 * signed-64-bit integer ceiling (~9.2e18), so sqlite's NUMERIC affinity stores
 * them EXACTLY (as integers, well under 2^53) and the figures asserted here are
 * trustworthy. The larger DECIMAL(20,0) values that sqlite cannot round-trip
 * (see BuildsGridSchema) are never exercised by these expectations.
 *
 * TIMEZONE: this step writes NO assertion on a DB-round-tripped datetime — the
 * two columns under test are an integer count and an integer IRT figure — so the
 * seven-times-over timestamp trap does not apply. The whole file was
 * nevertheless run under APP_TIMEZONE=Asia/Tehran as well as UTC, both green
 * (reported), so a future timestamp assertion added here has a known-good
 * baseline.
 */
final class GridOrderObserverTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    private function bot(bool $simulation = true): BotConfig
    {
        return BotConfigFactory::new()
            ->state(['simulation' => $simulation])
            ->create();
    }

    /**
     * A cycle_exit order sitting at 'placed' — one open cycle by the observer's
     * definition. $side selects buy vs sell.
     */
    private function openCycle(BotConfig $bot, string $side, array $overrides = []): GridOrder
    {
        $factory = GridOrderFactory::new()->cycleExit()->placed();
        $factory = $side === 'sell' ? $factory->sell() : $factory->buy();

        return $factory->create(array_merge(['bot_config_id' => $bot->id], $overrides));
    }

    /**
     * The FILLED counterpart buy that a sell-side open cycle is paired against —
     * the order whose price × amount becomes the locked notional. Modelled as an
     * initial_grid fill so it never itself counts as an open cycle.
     */
    private function counterpartBuy(BotConfig $bot, int|string $price, string $amount): GridOrder
    {
        return GridOrderFactory::new()->initialGrid()->buy()->filled()->create([
            'bot_config_id' => $bot->id,
            'price'         => $price,
            'amount'        => $amount,
        ]);
    }

    /** open_cycles_count as an exact int (integer-cast column; 0..255). */
    private function openCount(BotConfig $bot): ?int
    {
        $value = $bot->fresh()->open_cycles_count;

        return $value === null ? null : (int) $value;
    }

    /**
     * capital_locked_irt as an exact int. The column is deliberately UNCAST on
     * the model (kept a decimal string in production to dodge 32-bit truncation),
     * but every value asserted here is ~1e7..1e9 — far below 2^53 — so an int
     * cast is exact whether sqlite hands back an int, a REAL, or a string.
     */
    private function lockedIrt(BotConfig $bot): ?int
    {
        $value = $bot->fresh()->capital_locked_irt;

        return $value === null ? null : (int) $value;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // open_cycles_count
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * A fresh bot with no orders. The observer never fired, so the counter is
     * NULL (the migration's "not yet computed"); once the observer DOES aggregate
     * an order-less bot it writes 0 (computed-and-empty). We drive the observer's
     * own public aggregator to pin that empty definition without inventing an
     * order.
     *
     * CHARACTERIZATION: NULL and 0 are distinct states — NULL = never computed,
     * 0 = computed with nothing open. A fresh bot is NULL, not 0.
     */
    public function test_fresh_bot_is_null_and_empty_recompute_yields_zero(): void
    {
        $bot = $this->bot();

        $this->assertNull($this->openCount($bot), 'Pre-condition: a fresh bot has never been computed (NULL).');
        $this->assertNull($this->lockedIrt($bot), 'Pre-condition: capital_locked_irt is NULL until first computed.');

        (new GridOrderObserver())->recomputeInventoryForBot($bot);

        $this->assertSame(0, $this->openCount($bot), 'An order-less bot must aggregate to 0 open cycles.');
        $this->assertSame(0, $this->lockedIrt($bot), 'An order-less bot must aggregate to 0 locked IRT.');
    }

    /**
     * Place exactly N cycle_exit orders at 'placed' (both sides) and surround them
     * with noise that must NOT count: cycle_exit rows in other statuses and a
     * placed order in a different role. The count must be exactly N.
     *
     * CHARACTERIZATION: the count is NOT "all cycle_exit rows" and NOT "all placed
     * rows" — it is the intersection role='cycle_exit' AND status='placed',
     * side-agnostic. Here N = 3 (two sells + one buy at placed); the filled/
     * cancelled cycle_exit rows and the placed initial_grid row are excluded.
     */
    public function test_only_placed_cycle_exit_orders_of_either_side_are_counted(): void
    {
        $bot = $this->bot();

        // Counted: three cycle_exit @ placed (2 sell, 1 buy).
        $this->openCycle($bot, 'sell');
        $this->openCycle($bot, 'sell');
        $this->openCycle($bot, 'buy');

        // Noise 1: cycle_exit but already filled — the round-trip closed.
        GridOrderFactory::new()->cycleExit()->sell()->filled()->create(['bot_config_id' => $bot->id]);
        // Noise 2: cycle_exit but cancelled.
        GridOrderFactory::new()->cycleExit()->buy()->cancelled()->create(['bot_config_id' => $bot->id]);
        // Noise 3: placed, but role initial_grid — not a continuation cycle.
        GridOrderFactory::new()->initialGrid()->buy()->placed()->create(['bot_config_id' => $bot->id]);

        $this->assertSame(
            3,
            $this->openCount($bot),
            'Only placed cycle_exit orders (either side) count — filled/cancelled cycle_exit and placed initial_grid must be excluded.'
        );
    }

    /**
     * A single order moving INTO and OUT OF the counted state must move the count
     * in both directions. status is the axis here: placed (counted) ⇄ filled
     * (not counted).
     */
    public function test_transitioning_in_and_out_of_the_counted_state_moves_the_count_both_ways(): void
    {
        $bot   = $this->bot();
        $order = $this->openCycle($bot, 'buy');

        $this->assertSame(1, $this->openCount($bot), 'A placed cycle_exit is one open cycle.');

        // OUT of the counted state.
        $order->update(['status' => 'filled']);
        $this->assertSame(0, $this->openCount($bot), 'Filling the cycle_exit drops the count to 0.');

        // Back IN.
        $order->update(['status' => 'placed']);
        $this->assertSame(1, $this->openCount($bot), 'Re-placing it brings the count back to 1.');

        // And the OTHER axis — role — also moves it out.
        $order->update(['role' => 'initial_grid']);
        $this->assertSame(0, $this->openCount($bot), 'Changing role off cycle_exit drops the count to 0.');
    }

    /**
     * Deleting a counted order decrements the count via the deleted() hook.
     */
    public function test_deleting_a_counted_order_decrements_the_count(): void
    {
        $bot = $this->bot();
        $a   = $this->openCycle($bot, 'sell');
        $b   = $this->openCycle($bot, 'buy');

        $this->assertSame(2, $this->openCount($bot), 'Two placed cycle_exit orders → 2.');

        $a->delete();
        $this->assertSame(1, $this->openCount($bot), 'Deleting one counted order → 1.');

        $b->delete();
        $this->assertSame(0, $this->openCount($bot), 'Deleting the last counted order → 0.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // capital_locked_irt
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * A buy-side open cycle counts toward open_cycles_count but locks NO capital:
    *  a cycle_exit buy means we sold earlier and hold IRT to redeploy — that IRT
     * is still available. Also proves the "nothing locked" figure is 0, not NULL,
     * once the observer has run.
     *
     * CHARACTERIZATION: open_cycles_count and capital_locked_irt are independent —
     * an open cycle can add to the count while contributing zero locked capital.
     */
    public function test_a_buy_side_open_cycle_counts_but_locks_no_capital(): void
    {
        $bot = $this->bot();

        $this->openCycle($bot, 'buy', ['paired_order_id' => null]);

        $this->assertSame(1, $this->openCount($bot), 'The buy-side cycle_exit is one open cycle.');
        $this->assertSame(0, $this->lockedIrt($bot), 'A buy-side open cycle locks nothing → 0 IRT.');
    }

    /**
     * One open SELL-side cycle whose paired buy has a known price × amount. The
     * locked figure must equal that buy's notional — computed independently below
     * with bcmath, NOT read back from the observer.
     *
     * CHARACTERIZATION: the notional is taken from the PAIRED order
     * (paired_order_id → buy), never from the sell's own columns. Here the sell
     * carries a deliberately DIFFERENT price/amount (99e9 × 0.002) than the buy
     * (98e9 × 0.001); the locked figure is the buy's 98,000,000, proving the
     * counterpart is what is read.
     */
    public function test_one_open_sell_cycle_locks_its_paired_buy_notional(): void
    {
        $bot = $this->bot();

        // Independent expectation: 98,000,000,000 IRT/BTC × 0.00100000 BTC.
        $buyPrice  = '98000000000';
        $buyAmount = '0.00100000';
        $expected  = Money::add(Money::mul($buyPrice, $buyAmount), '0', 0); // floor to integer IRT
        $this->assertSame('98000000', $expected, 'Sanity: the hand-computed notional is 98,000,000 IRT.');

        $buy = $this->counterpartBuy($bot, $buyPrice, $buyAmount);

        // Sell's own price/amount are intentionally different and must be ignored.
        $this->openCycle($bot, 'sell', [
            'price'           => '99000000000',
            'amount'          => '0.00200000',
            'paired_order_id' => $buy->id,
        ]);

        $this->assertSame(1, $this->openCount($bot), 'The sell-side cycle_exit is one open cycle.');
        $this->assertSame(
            (int) $expected,
            $this->lockedIrt($bot),
            'Locked capital must equal the PAIRED BUY notional (98,000,000), not the sell order’s own price × amount.'
        );
    }

    /**
     * Multiple open sell cycles sum their paired notionals.
     */
    public function test_multiple_open_sell_cycles_sum_their_locked_capital(): void
    {
        $bot = $this->bot();

        $buy1 = $this->counterpartBuy($bot, '98000000000', '0.00100000'); // 98,000,000
        $buy2 = $this->counterpartBuy($bot, '99000000000', '0.00200000'); // 198,000,000

        $this->openCycle($bot, 'sell', ['paired_order_id' => $buy1->id]);
        $this->openCycle($bot, 'sell', ['paired_order_id' => $buy2->id]);

        $expected = Money::add(
            Money::add(Money::mul('98000000000', '0.00100000'), Money::mul('99000000000', '0.00200000')),
            '0',
            0
        );
        $this->assertSame('296000000', $expected, 'Sanity: 98,000,000 + 198,000,000 = 296,000,000.');

        $this->assertSame(2, $this->openCount($bot), 'Two open sell cycles.');
        $this->assertSame((int) $expected, $this->lockedIrt($bot), 'Locked capital must be the sum of both paired notionals.');
    }

    /**
     * Filling a sell cycle (status placed → filled) releases its locked capital:
     * the count drops and the IRT figure returns to 0.
     */
    public function test_filling_the_sell_cycle_releases_the_locked_capital(): void
    {
        $bot  = $this->bot();
        $buy  = $this->counterpartBuy($bot, '98000000000', '0.00100000');
        $sell = $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);

        $this->assertSame(98000000, $this->lockedIrt($bot), 'Pre-condition: 98,000,000 IRT locked.');
        $this->assertSame(1, $this->openCount($bot));

        $sell->update(['status' => 'filled']);

        $this->assertSame(0, $this->openCount($bot), 'The closed cycle no longer counts.');
        $this->assertSame(0, $this->lockedIrt($bot), 'Closing the cycle releases the locked capital back to 0.');
    }

    /**
     * Deleting the sell cycle likewise releases its locked capital, via deleted().
     */
    public function test_deleting_the_sell_cycle_releases_the_locked_capital(): void
    {
        $bot  = $this->bot();
        $buy  = $this->counterpartBuy($bot, '98000000000', '0.00100000');
        $sell = $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);

        $this->assertSame(98000000, $this->lockedIrt($bot), 'Pre-condition: 98,000,000 IRT locked.');

        $sell->delete();

        $this->assertSame(0, $this->openCount($bot), 'The deleted cycle no longer counts.');
        $this->assertSame(0, $this->lockedIrt($bot), 'Deleting the open cycle releases the locked capital.');
    }

    /**
     * Precision at a realistic BTCIRT magnitude — price ~9.85e10, amount within
     * the stated 1e-4..1e-2 window — yielding a whole-IRT notional. sqlite stores
     * the ~1.2e8 result exactly (integer affinity, far below 2^53 and 9.2e18), so
     * this exact assertion is trustworthy on the sqlite harness.
     */
    public function test_precision_at_realistic_btcirt_magnitude(): void
    {
        $bot = $this->bot();

        // 98,500,000,000 IRT/BTC × 0.00123456 BTC = 121,604,160 IRT exactly.
        $buy = $this->counterpartBuy($bot, '98500000000', '0.00123456');
        $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);

        $expected = Money::add(Money::mul('98500000000', '0.00123456'), '0', 0);
        $this->assertSame('121604160', $expected, 'Sanity: the hand-computed notional is 121,604,160 IRT.');

        $this->assertSame(121604160, $this->lockedIrt($bot), 'The realistic-magnitude notional round-trips exactly.');
    }

    /**
     * A paired notional with a fractional IRT tail is FLOORED (truncated toward
     * zero), because the observer collapses the sum to integer scale with
     * Money::add(sum, '0', 0) — a scale-0 bcadd, which truncates rather than
     * rounds.
     *
     * CHARACTERIZATION: the sub-IRT remainder is dropped, not rounded to nearest.
     * Here 98,000,000,001 × 0.00000001 = 980.00000001 IRT → stored as 980. This
     * bites at the sub-rial tail of any notional whose price × amount is not a
     * whole number of rials; at BTCIRT magnitude that tail is < 1 IRT, i.e.
     * economically negligible but pinned so a future switch to round-half-up
     * would be caught.
     */
    public function test_fractional_notional_is_floored_not_rounded(): void
    {
        $bot = $this->bot();

        $buy = $this->counterpartBuy($bot, '98000000001', '0.00000001');
        $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);

        $full   = Money::mul('98000000001', '0.00000001'); // 980.00000001
        $floored = Money::add($full, '0', 0);              // 980
        $this->assertSame('980.00000001', $full, 'Sanity: the untruncated notional carries a sub-IRT tail.');
        $this->assertSame('980', $floored, 'Sanity: scale-0 bcadd truncates the tail.');

        $this->assertSame(980, $this->lockedIrt($bot), 'The observer stores the FLOORED integer IRT (980), dropping the 0.00000001 tail.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // The swallow path — assert the WRITTEN values, not the absence of an error
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The single most important guarantee of this step. A normal save of a
     * sell-side open cycle must leave BOTH columns holding their correct NEW,
     * NON-NULL values — proving the recompute ran to completion and persisted,
     * NOT that it threw, got swallowed by the try/catch(\Throwable), and left the
     * columns at their NULL "never computed" pre-state.
     *
     * A test that only asserted "no exception was thrown" would pass even if the
     * write silently failed (the Step 5 missing-column scenario), which is why
     * both values are pinned to concrete non-nulls here.
     */
    public function test_recompute_actually_persists_new_values_not_a_swallowed_noop(): void
    {
        $bot = $this->bot();

        // Pre-state is the swallow-path tell: NULL means "never written".
        $this->assertNull($bot->fresh()->open_cycles_count);
        $this->assertNull($bot->fresh()->capital_locked_irt);

        $buy = $this->counterpartBuy($bot, '98000000000', '0.00100000');
        $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);

        $fresh = $bot->fresh();

        // NON-NULL + exact: a swallowed recompute would have left these NULL.
        $this->assertNotNull($fresh->open_cycles_count, 'open_cycles_count must be written, not left NULL by a swallowed failure.');
        $this->assertNotNull($fresh->capital_locked_irt, 'capital_locked_irt must be written, not left NULL by a swallowed failure.');
        $this->assertSame(1, (int) $fresh->open_cycles_count, 'The recompute wrote open_cycles_count = 1.');
        $this->assertSame(98000000, (int) $fresh->capital_locked_irt, 'The recompute wrote capital_locked_irt = 98,000,000.');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // simulation
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The observer does NOT gate on the simulation flag — recompute() resolves
     * the bot and recomputes regardless. A simulation bot and a live bot fed the
     * identical order shape end up with the identical accounting.
     *
     * CHARACTERIZATION: inventory tracking is unconditional across sim/live; there
     * is no `if ($bot->simulation) return;` short-circuit.
     */
    public function test_observer_recomputes_for_simulation_and_live_bots_alike(): void
    {
        $simBot  = $this->bot(simulation: true);
        $liveBot = $this->bot(simulation: false);

        $this->assertTrue($simBot->fresh()->simulation, 'Pre-condition: sim bot is a simulation.');
        $this->assertFalse($liveBot->fresh()->simulation, 'Pre-condition: live bot is not.');

        foreach ([$simBot, $liveBot] as $bot) {
            $buy = $this->counterpartBuy($bot, '98000000000', '0.00100000');
            $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);
        }

        $this->assertSame(1, $this->openCount($simBot));
        $this->assertSame(98000000, $this->lockedIrt($simBot));

        $this->assertSame(1, $this->openCount($liveBot), 'A live bot recomputes identically — simulation is not gated.');
        $this->assertSame(98000000, $this->lockedIrt($liveBot));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // idempotence / double-fire
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Re-saving the same order — first with no field change, then with a benign
     * field change — must not double-count. The observer recomputes by full
     * re-aggregation (a COUNT query), so repeated saves are naturally idempotent;
     * this pins that it is a recompute and NOT an increment that would drift on
     * every scheduler tick or queue retry.
     */
    public function test_saving_the_same_order_repeatedly_does_not_double_count(): void
    {
        $bot   = $this->bot();
        $buy   = $this->counterpartBuy($bot, '98000000000', '0.00100000');
        $sell  = $this->openCycle($bot, 'sell', ['paired_order_id' => $buy->id]);

        $this->assertSame(1, $this->openCount($bot));
        $this->assertSame(98000000, $this->lockedIrt($bot));

        // Save again with NO change to any field.
        $sell->save();
        $this->assertSame(1, $this->openCount($bot), 'A no-op save must not inflate the count.');
        $this->assertSame(98000000, $this->lockedIrt($bot), 'A no-op save must not inflate the locked capital.');

        // Save again with a benign, non-accounting field change.
        $sell->update(['client_order_id' => 'grid-test-rewritten']);
        $this->assertSame(1, $this->openCount($bot), 'A benign field change must not inflate the count.');
        $this->assertSame(98000000, $this->lockedIrt($bot), 'A benign field change must not inflate the locked capital.');
    }
}
