<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W3 — raw log of the user's own Nobitex private WebSocket events
     * (private:orders / private:trades), written by nobitex:ws-private.
     * OBSERVE-ONLY: nothing reads this table to trade.
     *
     * ⚠ Requires `php artisan migrate` on the host.
     *
     * Self-contained and additive (new table, no FKs, no raw DDL), so it runs on
     * MySQL and on the test suite's sqlite connection alike.
     *
     * Idempotency (reconnect replays must never duplicate rows; the writer uses
     * insertOrIgnore):
     *   • trades: unique (channel, trade_id)
     *   • orders: unique (channel, nobitex_order_id, status, event_time_ms)
     * trade_id is populated for trades-channel rows only — an orders event's
     * tradeId stays in `payload` — so two order events that share a tradeId can
     * never collide on the trades key. NULLs are distinct in both MySQL and
     * sqlite unique indexes, so rows missing a key part (e.g. the "Failed"
     * orders variant, which has no orderId/eventTime) are always recorded.
     *
     * Index names are explicit: the generated ones exceed MySQL's 64-char limit.
     */
    public function up(): void
    {
        Schema::create('exchange_ws_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16); // 'orders' | 'trades'
            $table->unsignedBigInteger('nobitex_order_id')->nullable();
            $table->unsignedBigInteger('trade_id')->nullable();
            $table->string('client_order_id', 64)->nullable();
            $table->string('status', 16)->nullable();
            $table->string('market_type', 16)->nullable();
            $table->unsignedBigInteger('event_time_ms')->nullable();
            $table->json('payload');
            $table->unsignedBigInteger('matched_grid_order_id')->nullable();
            $table->string('local_status_at_receipt', 32)->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->index('nobitex_order_id', 'ews_nobitex_order_id_idx');
            $table->index('matched_grid_order_id', 'ews_matched_grid_order_id_idx');
            $table->index('received_at', 'ews_received_at_idx');
            $table->unique(['channel', 'trade_id'], 'ews_trades_unique');
            $table->unique(['channel', 'nobitex_order_id', 'status', 'event_time_ms'], 'ews_orders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_ws_events');
    }
};
