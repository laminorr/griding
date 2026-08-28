<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\DTOs\OrderStatusDto;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;
use App\Models\GridOrder;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Order-lifecycle hardening — CANCELED order that carried a partial fill.
 *
 * Nobitex docs (order status): a Canceled order MAY report matchedAmount > 0,
 * i.e. part of it executed before the cancellation landed. The old
 * handleCanceledOrder() ignored that quantity and merely flipped the row to
 * 'cancelled', silently discarding a real executed trade from the ledger.
 *
 * The contract under test:
 *   - matchedAmount > 0  → filled_amount / remaining_amount / average_fill_price
 *     / last_fill_at are persisted, the row ends in a TERMINAL 'cancelled'
 *     state, and NO pair/continuation order is spawned for the canceled
 *     remainder (processBot() only pairs rows with status 'filled').
 *   - matchedAmount == 0 → unchanged: a plain 'cancelled' row.
 *
 * handleCanceledOrder() is private and needs no constructor args, so it is
 * invoked directly via reflection with a hand-built GridOrder + DTO.
 *
 * NOTE: the > 0 comparison uses the project's BCMath Money helper (never a
 * naive float ==). ext-bcmath is required to run these assertions.
 */
final class CheckTradesCanceledPartialTest extends TestCase
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
        parent::tearDown();
    }

    private function makeBot(): BotConfig
    {
        return BotConfig::create([
            'name'         => 'cancel-partial-test',
            'symbol'       => self::SYMBOL,
            'simulation'   => false,
            'is_active'    => true,
            'grid_spacing' => 1.00,
        ]);
    }

    private function makePlacedBuy(BotConfig $bot): GridOrder
    {
        return GridOrder::create([
            'bot_config_id'    => $bot->id,
            'price'            => 100_000_000,
            'amount'           => '0.001',
            'original_amount'  => '0.001',
            'type'             => 'buy',
            'status'           => 'placed',
            'client_order_id'  => 'seed-cancel-partial',
            'nobitex_order_id' => '555001',
            'paired_order_id'  => null,
        ]);
    }

    private function canceledDto(string $matchedAmount): OrderStatusDto
    {
        return OrderStatusDto::fromApi([
            'id'            => '555001',
            'status'        => 'Canceled',
            'type'          => 'buy',
            'execution'     => 'limit',
            'amount'        => '0.001',
            'matchedAmount' => $matchedAmount,
            'price'         => 100_000_000,
            'createdAt'     => 1_700_000_000_000,
        ]);
    }

    private function invokeHandleCanceled(GridOrder $order, OrderStatusDto $dto, BotConfig $bot): void
    {
        $job = new CheckTradesJob();
        $ref = new ReflectionMethod($job, 'handleCanceledOrder');
        $ref->setAccessible(true);
        $ref->invoke($job, $order, $dto, $bot);
    }

    public function test_canceled_with_partial_fill_persists_executed_quantity_and_stays_terminal(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makePlacedBuy($bot);

        // 0.0004 of the 0.001 executed before the cancel landed.
        $this->invokeHandleCanceled($order, $this->canceledDto('0.0004'), $bot);

        $fresh = $order->fresh();

        // (b) terminal cancelled state
        $this->assertSame('cancelled', $fresh->status);

        // (a) executed quantity persisted — not lost
        $this->assertSame('0.00040000', (string) $fresh->filled_amount);
        $this->assertSame('0.00060000', (string) $fresh->remaining_amount);
        $this->assertNotNull($fresh->last_fill_at);
        $this->assertSame('100000000', (string) $fresh->average_fill_price);

        // (c) NO pair/continuation order created for the canceled remainder.
        // processBot() only pairs status == 'filled'; a 'cancelled' row must
        // never be selected. Assert directly that nothing was spawned and the
        // row was not linked to a continuation.
        $this->assertNull($fresh->paired_order_id);
        $this->assertSame(
            1,
            GridOrder::where('bot_config_id', $bot->id)->count(),
            'A canceled-with-partial order must not spawn a continuation/pair order.'
        );
    }

    public function test_canceled_with_zero_matched_is_a_plain_cancel(): void
    {
        $bot   = $this->makeBot();
        $order = $this->makePlacedBuy($bot);

        $this->invokeHandleCanceled($order, $this->canceledDto('0'), $bot);

        $fresh = $order->fresh();

        $this->assertSame('cancelled', $fresh->status);
        // No fill was recorded — the ledger fill fields stay untouched.
        $this->assertNull($fresh->filled_amount);
        $this->assertNull($fresh->last_fill_at);
        $this->assertNull($fresh->paired_order_id);
        $this->assertSame(1, GridOrder::where('bot_config_id', $bot->id)->count());
    }
}
