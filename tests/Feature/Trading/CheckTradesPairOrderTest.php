<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase 12 Step 1 — behaviour lock-in for CheckTradesJob::createPairOrderLocked()
 * (CheckTradesJob.php :692-855).
 *
 * Documents the CURRENT asymmetry between the two order-placement paths: the
 * executor (Part B) writes its intent row OUTSIDE any transaction and downgrades
 * it to 'submission_unknown' on a post-call throw, whereas the pair-order path
 * wraps its intent row in a DB transaction that ROLLS BACK on error — so the
 * row vanishes and no reconciliation trace survives. That divergence is the
 * Phase 12 Step 6 target.
 *
 * createPairOrderLocked() is private and needs no CheckTradesJob constructor
 * args, so it is invoked directly via reflection with a hand-built filled order
 * and bot. Schema is the sqlite-friendly one from BuildsGridSchema (the real
 * MySQL-only migrations cannot run on :memory:).
 */
final class CheckTradesPairOrderTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL     = 'BTCIRT';
    private const BUY_PRICE  = 100_000_000;
    // sell continuation = buy_price * (1 + grid_spacing/100), grid_spacing = 1.00
    private const SELL_PRICE = 101_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(bool $simulation): BotConfig
    {
        return BotConfig::create([
            'name'         => 'pair-test',
            'symbol'       => self::SYMBOL,
            'simulation'   => $simulation,
            'is_active'    => true,
            'grid_spacing' => 1.00,
        ]);
    }

    private function makeFilledBuy(BotConfig $bot): GridOrder
    {
        return GridOrder::create([
            'bot_config_id'   => $bot->id,
            'price'           => self::BUY_PRICE,
            'amount'          => '0.001',
            'filled_amount'   => '0.001',
            'type'            => 'buy',
            'status'          => 'filled',
            'client_order_id' => 'seed-buy-' . self::BUY_PRICE,
            'paired_order_id' => null,
        ]);
    }

    private function invokePairOrder(GridOrder $filled, BotConfig $bot): void
    {
        $job = new CheckTradesJob();
        $ref = new ReflectionMethod($job, 'createPairOrderLocked');
        $ref->setAccessible(true);
        $ref->invoke($job, $filled, $bot);
    }

    private function expectedPairClientOrderId(BotConfig $bot): string
    {
        return GridOrder::buildClientOrderId($bot->id, self::SYMBOL, 'sell', self::SELL_PRICE);
    }

    /**
     * 1. KNOWN BUG (Phase 12 Step 6 will fix this).
     *
     * On a live pair order, the 'pending' intent row is created INSIDE a
     * DB transaction and the exchange call happens before commit. When
     * placeOrder() throws, the catch block calls DB::rollBack() (:844) — which
     * erases the intent row entirely. The executor path keeps such a row as
     * 'submission_unknown' for reconciliation; here it is simply GONE, so a
     * genuinely-placed exchange order can be orphaned with no local record.
     *
     * When Step 6 moves the intent row outside the rolled-back transaction (or
     * mirrors the submission_unknown guard), this test must change to assert the
     * row SURVIVES. Renaming it away from test_known_bug_* signals the fix.
     */
    public function test_known_bug_pair_intent_row_lost_on_rollback(): void
    {
        $bot    = $this->makeBot(simulation: false);
        $filled = $this->makeFilledBuy($bot);

        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('placeOrder')
            ->once()
            ->andThrow(new RuntimeException('cURL error 28: Operation timed out'));
        $this->app->instance(NobitexService::class, $svc);

        $this->invokePairOrder($filled, $bot);

        // The pending pair intent row was rolled back out of existence.
        $this->assertFalse(
            GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->exists(),
            'Current behaviour: the pair intent row is lost on rollback (Step 6 target).'
        );
        // The original filled order (created before the transaction) is untouched
        // and remains unpaired, since pairing only completes after a successful call.
        $this->assertNull($filled->fresh()->paired_order_id);
    }

    /** 2. Simulation assigns a SIM-* id, commits the row, and sends nothing. */
    public function test_simulation_pair_order_assigns_sim_id_without_http(): void
    {
        Http::fake();

        $bot    = $this->makeBot(simulation: true);
        $filled = $this->makeFilledBuy($bot);

        $this->invokePairOrder($filled, $bot);

        Http::assertNothingSent();

        $pair = GridOrder::where('client_order_id', $this->expectedPairClientOrderId($bot))->first();
        $this->assertNotNull($pair, 'Simulation must persist the pair order row.');
        $this->assertSame('placed', $pair->status);
        $this->assertSame('sell', $pair->type);
        $this->assertStringStartsWith('SIM-', (string) $pair->nobitex_order_id);
        // Simulation completes the pairing back-reference on the filled order.
        $this->assertSame($pair->id, $filled->fresh()->paired_order_id);
    }
}
