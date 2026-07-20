<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Services\NobitexService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 12 Step 7b — order-tag field-name regression guard.
 *
 * The official Nobitex docs (apidocs.nobitex.ir) name the client-supplied
 * order tag `clientOrderId` on POST /market/orders/add; order objects echo it
 * back under the same key. An earlier step standardised the codebase on
 * `client_ref`, a field Nobitex does not document and almost certainly ignores
 * silently — meaning the Phase 4 idempotency tagging was never actually
 * active. These tests pin the DOCUMENTED name on every order-creating path:
 *
 *   - NobitexService::placeOrder()   — the call GridRunOnce's live loop and
 *                                      CheckTradesJob::createPairOrderLocked()
 *                                      both delegate to.
 *   - NobitexService::createOrder()  — the CreateOrderDto path used by
 *                                      GridOrderExecutor::applyForBot().
 *
 * SCOPE NOTE: the full `grid:run --live` command is NOT invoked here. It needs
 * the MySQL-only grid_runs/grid_events/grid_run_orders schema (absent from the
 * sqlite test schema) plus a bound MarketData planner producing to_place items.
 * Instead these drive the precise service methods the placement paths delegate
 * to, which is where the field name actually lives. What is verified: the wire
 * body carries `clientOrderId` and NOT the undocumented `client_ref`.
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

    /** Assert the /market/orders/add body tags the order with clientOrderId only. */
    private function assertOrderAddSentClientOrderId(string $expected): void
    {
        Http::assertSent(function ($request) use ($expected) {
            if (! str_contains($request->url(), '/market/orders/add')) {
                return false;
            }

            $body = $request->data();

            $this->assertArrayHasKey('clientOrderId', $body, 'Order must carry the documented clientOrderId tag.');
            $this->assertArrayNotHasKey('client_ref', $body, 'The undocumented client_ref field must be gone.');
            $this->assertSame($expected, $body['clientOrderId']);

            return true;
        });
    }

    public function test_place_order_sends_client_order_id_not_client_ref(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/*' => Http::response(['status' => 'ok', 'order' => ['id' => 999]], 200),
        ]);

        // Mirror exactly how GridRunOnce's live loop (and CheckTradesJob's
        // pair-order placement) call the service.
        $clientRef = 'grid-run-42-'.uniqid();
        (new NobitexService())->placeOrder('BTCIRT', 'buy', 100_000_000, '0.001', $clientRef);

        $this->assertOrderAddSentClientOrderId($clientRef);
    }

    public function test_create_order_sends_client_order_id_not_client_ref(): void
    {
        Http::fake([
            'apiv2.nobitex.ir/*' => Http::response(['status' => 'ok', 'order' => ['id' => 1000]], 200),
        ]);

        // Mirror how GridOrderExecutor::applyForBot() builds its DTO.
        $clientOrderId = 'grid:7:BTCIRT:sell:101000000';
        (new NobitexService())->createOrder(new CreateOrderDto(
            side:        OrderSide::SELL,
            execution:   ExecutionType::LIMIT,
            srcCurrency: 'btc',
            dstCurrency: 'irt',
            amountBase:  '0.001',
            priceIRT:    101_000_000,
            clientRef:   $clientOrderId,
        ));

        $this->assertOrderAddSentClientOrderId($clientOrderId);
    }

    public function test_create_order_dto_payload_uses_client_order_id_key(): void
    {
        $payload = (new CreateOrderDto(
            side:        OrderSide::BUY,
            execution:   ExecutionType::LIMIT,
            srcCurrency: 'btc',
            dstCurrency: 'irt',
            amountBase:  '0.002',
            priceIRT:    99_000_000,
            clientRef:   'grid:1:BTCIRT:buy:99000000',
        ))->toApiPayload();

        $this->assertSame('grid:1:BTCIRT:buy:99000000', $payload['clientOrderId'] ?? null);
        $this->assertArrayNotHasKey('client_ref', $payload);
    }
}
