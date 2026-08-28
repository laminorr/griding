<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\DTOs\ApiOkDto;
use App\DTOs\OrderStatusDto;
use App\Models\GridOrder;
use App\Services\GridOrderExecutor;
use App\Services\NobitexService;
use App\Support\OrderRegistry;
use Mockery;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Order-lifecycle hardening — a cancel that comes back status:"failed"
 * because the order already FILLED must NOT lose the fill.
 *
 * Nobitex returns HTTP 200 with status:"failed" when a cancel transition can't
 * be applied; the most important real cause is that the order just filled and
 * is no longer cancellable. The old code discarded cancelOrder()'s result and
 * unconditionally marked the local GridOrder 'cancelled', erasing a real fill:
 * once 'cancelled', the order is never polled again (processBot() only polls
 * 'placed'/'partially_filled'), so its fill and continuation were lost forever.
 *
 * The fix leaves the local row in its LIVE status on a failed cancel, so
 * CheckTradesJob's next status poll (by numeric id — which resolves filled
 * orders, unlike a clientOrderId lookup) runs the normal handleFilledOrder path
 * and books the trade / creates the continuation pair. This test locks the
 * critical property: the failed-because-filled cancel does NOT flip the row to
 * 'cancelled'.
 */
final class CancelFailedFilledTest extends TestCase
{
    use BuildsGridSchema;

    private const SYMBOL = 'BTCIRT';
    private const BOT_ID = 1;

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

    private function makeLivePlacedOrder(string $nobitexId): GridOrder
    {
        return GridOrder::create([
            'bot_config_id'    => self::BOT_ID,
            'price'            => 100_000_000,
            'amount'           => '0.001',
            'type'             => 'buy',
            'status'           => 'placed',
            'client_order_id'  => 'seed-cancel-failed',
            'nobitex_order_id' => $nobitexId,
        ]);
    }

    private function filledDto(string $nobitexId): OrderStatusDto
    {
        return OrderStatusDto::fromApi([
            'id'            => $nobitexId,
            'status'        => 'Done', // Nobitex 'Done' -> our FILLED
            'type'          => 'buy',
            'execution'     => 'limit',
            'amount'        => '0.001',
            'matchedAmount' => '0.001',
            'price'         => 100_000_000,
            'createdAt'     => 1_700_000_000_000,
        ]);
    }

    public function test_failed_cancel_because_filled_does_not_cancel_local_row(): void
    {
        $order = $this->makeLivePlacedOrder('777001');

        $svc = Mockery::mock(NobitexService::class);
        // Cancel comes back HTTP-200 status:"failed".
        $svc->shouldReceive('cancelOrder')
            ->once()
            ->with('777001')
            ->andReturn(new ApiOkDto(false, 'failed'));
        // The executor re-observes the real state by numeric id and sees FILLED.
        $svc->shouldReceive('getOrdersStatus')
            ->once()
            ->with(['777001'])
            ->andReturn([$this->filledDto('777001')]);

        $executor = new GridOrderExecutor($svc, new OrderRegistry());

        $diff = [
            'symbol'    => self::SYMBOL,
            'tick'      => 1,
            'to_cancel' => ['777001'],
            'to_place'  => [],
        ];

        $executor->applyForBot(self::BOT_ID, $diff, simulation: false);

        // The fill is NOT lost: the row is still live (not 'cancelled'), so
        // CheckTradesJob's next poll drives it through handleFilledOrder.
        $this->assertSame(
            'placed',
            $order->fresh()->status,
            'A cancel that failed because the order filled must never mark the local row cancelled.'
        );
    }
}
