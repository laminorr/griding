<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Services\NobitexService;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Order-lifecycle hardening (verification) — reconciliation of a possibly-FILLED
 * order must key on the numeric order id, never the clientOrderId.
 *
 * Nobitex docs: a clientOrderId lookup (orders/status?clientOrderId=…) only
 * searches OPEN orders (New/Active/Inactive) and answers NotFound for a
 * Done/Canceled order. So the status poll that observes a fill MUST use the
 * numeric order id.
 *
 * CheckTradesJob::checkOrdersStatus() collects ids via
 * pluck('nobitex_order_id') (the numeric exchange id) and calls
 * getOrdersStatus() with them — never client_order_id. This test locks that: a
 * placed order whose client_order_id differs from its nobitex_order_id is polled
 * by the NUMERIC id. (SubmissionReconciler already relies on the same property:
 * on a clientOrderId NotFound it corroborates via listRecentTrades() and
 * resolves the row with the numeric exchange id, deferring the fill accounting
 * to exactly this numeric-id poll — see SubmissionReconciler::resolveRow.)
 */
final class FilledReconciliationIdKeyTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL = 'BTCIRT';

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

    public function test_status_poll_uses_numeric_order_id_not_client_order_id(): void
    {
        $bot = BotConfig::create([
            'name'       => 'id-key-test',
            'symbol'     => self::SYMBOL,
            'simulation' => false,
            'is_active'  => true,
        ]);

        $order = GridOrder::create([
            'bot_config_id'    => $bot->id,
            'price'            => 100_000_000,
            'amount'           => '0.001',
            'type'             => 'buy',
            'status'           => 'placed',
            // Deliberately distinct so a lookup by the wrong key is detectable.
            'client_order_id'  => 'grid:1:BTCIRT:buy:100000000',
            'nobitex_order_id' => '9900123',
        ]);

        $svc = Mockery::mock(NobitexService::class);
        // The poll MUST request the numeric nobitex_order_id, NOT the
        // clientOrderId. Mockery's ->with() fails the test if the wrong key is
        // passed. Return [] so no further processing runs.
        $svc->shouldReceive('getOrdersStatus')
            ->once()
            ->with(['9900123'])
            ->andReturn([]);
        $this->app->instance(NobitexService::class, $svc);

        $job = new CheckTradesJob();
        $ref = new ReflectionMethod($job, 'checkOrdersStatus');
        $ref->setAccessible(true);
        $ref->invoke($job, collect([$order]), $bot);

        // Reaching here means getOrdersStatus was called exactly once with the
        // numeric id; Mockery verifies the expectation on tearDown.
        $this->assertTrue(true);
    }
}
