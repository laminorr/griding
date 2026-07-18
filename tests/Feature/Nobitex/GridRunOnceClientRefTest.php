<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 12 Step 4 — GridRunOnce idempotency-field regression guard.
 *
 * Before this step, `php artisan grid:run --live` placed orders with a RAW
 * Http::post that sent the idempotency field as `clientOrderId`, while the rest
 * of the system (NobitexService) sends `client_ref`. The command now routes
 * through NobitexService::placeOrder(), which is the exact call the command's
 * live loop makes:
 *
 *     $nobitex->placeOrder($symbol, $side, $price, $qty, "grid-run-{id}-{uniqid}")
 *
 * SCOPE NOTE: the full `grid:run --live` command is NOT invoked here. It needs
 * the MySQL-only grid_runs/grid_events/grid_run_orders schema (absent from the
 * sqlite test schema) plus a bound MarketData planner producing to_place items.
 * Instead this drives the precise method + arguments the command now delegates
 * to, which is where the field-name fix actually lives. What is verified: the
 * wire body carries `client_ref` and NOT `clientOrderId`.
 */
final class GridRunOnceClientRefTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trading.nobitex.base_url'       => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'        => '',
            'trading.nobitex.retry.times'    => 1,
            'trading.nobitex.retry.sleep'    => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
        ]);
    }

    public function test_place_order_sends_client_ref_not_client_order_id(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/*' => Http::response(['status' => 'ok', 'order' => ['id' => 999]], 200),
        ]);

        // Mirror exactly how GridRunOnce's live loop calls the service.
        $clientRef = 'grid-run-42-'.uniqid();
        (new NobitexService())->placeOrder('BTCIRT', 'buy', 100_000_000, '0.001', $clientRef);

        Http::assertSent(function ($request) use ($clientRef) {
            if (! str_contains($request->url(), '/market/orders/add')) {
                return false;
            }

            $body = $request->data();

            // The bug this step fixes: field must be `client_ref`, never `clientOrderId`.
            $this->assertArrayHasKey('client_ref', $body, 'Order must carry a client_ref idempotency field.');
            $this->assertArrayNotHasKey('clientOrderId', $body, 'The legacy clientOrderId field must be gone.');
            $this->assertSame($clientRef, $body['client_ref']);

            return true;
        });
    }
}
