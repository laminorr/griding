<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ExchangeWsEvent;
use App\Services\ExchangeWsEventRecorder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * W3 recording: exchange_ws_events built by the REAL migration on the sqlite
 * harness (BuildsGridSchema for grid_orders/bot_configs). Observe-only: the
 * grid order a private event matches must never be written.
 */
final class ExchangeWsEventRecorderTest extends TestCase
{
    use BuildsGridSchema;

    private const MIGRATION = 'database/migrations/2026_10_01_000001_create_exchange_ws_events_table.php';

    /** @var array<int,array{level:string,message:string,context:array}> */
    private array $logs = [];

    /** @var array<int,string> */
    private array $eloquentEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
        Schema::dropIfExists('exchange_ws_events');
        (require base_path(self::MIGRATION))->up();

        $this->logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('exchange_ws_events');
        $this->dropGridSchema();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private static function orderEvent(array $over = []): array
    {
        return array_merge([
            'orderId' => 4567890123,
            'tradeId' => null,
            'clientOrderId' => 'grid-abc-1',
            'srcCurrency' => 'btc',
            'dstCurrency' => 'rls',
            'eventTime' => 1759300000123,
            'lastFillTime' => null,
            'side' => 'Buy',
            'status' => 'New',
            'fee' => '0',
            'price' => '9800000000',
            'avgFilledPrice' => null,
            'tradePrice' => null,
            'amount' => '0.0001',
            'tradeAmount' => null,
            'filledAmount' => '0',
            'param1' => null,
            'orderType' => 'Limit',
            'marketType' => 'Spot',
        ], $over);
    }

    /** @return array<string,mixed> */
    private static function tradeEvent(array $over = []): array
    {
        return array_merge([
            'id' => 777001,
            'orderId' => 4567890123,
            'srcCurrency' => 'btc',
            'dstCurrency' => 'rls',
            'time' => 1759300001000,
            'timestamp' => '2025-10-01T10:00:01+03:30',
            'type' => 'Buy',
            'price' => '9800000000',
            'amount' => '0.0001',
            'total' => '980000',
            'fee' => '0.00000035',
            'isMaker' => true,
        ], $over);
    }

    private function recorder(): ExchangeWsEventRecorder
    {
        return new ExchangeWsEventRecorder();
    }

    private function seedGridOrder(string $nobitexOrderId, string $status = 'placed'): int
    {
        $botId = DB::table('bot_configs')->insertGetId([
            'name' => 'b', 'symbol' => 'BTCIRT', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        return DB::table('grid_orders')->insertGetId([
            'bot_config_id' => $botId,
            'price' => '9800000000',
            'amount' => '0.0001',
            'type' => 'buy',
            'status' => $status,
            'nobitex_order_id' => $nobitexOrderId,
            'role' => 'cycle_exit',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /* ----------------------------------- orders ----------------------------------- */

    public function test_order_event_as_json_string_is_stored_with_correct_columns(): void
    {
        $event = self::orderEvent();
        $this->assertSame(1, $this->recorder()->record('orders', json_encode($event)));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertSame('orders', $row->channel);
        $this->assertSame(4567890123, $row->nobitex_order_id);
        $this->assertNull($row->trade_id);
        $this->assertSame('grid-abc-1', $row->client_order_id);
        $this->assertSame('New', $row->status);
        $this->assertSame('Spot', $row->market_type);
        $this->assertSame(1759300000123, $row->event_time_ms);
        $this->assertSame($event, $row->payload);
        $this->assertNull($row->matched_grid_order_id);
        $this->assertNull($row->local_status_at_receipt);
        $this->assertNotNull($row->received_at);
    }

    public function test_order_event_as_array_is_stored(): void
    {
        $this->assertSame(1, $this->recorder()->record('orders', self::orderEvent(['marketType' => 'Margin', 'status' => 'Active'])));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertSame('Active', $row->status);
        $this->assertSame('Margin', $row->market_type);
        $this->assertSame('9800000000', $row->payload['price']); // decimal strings preserved
    }

    public function test_duplicate_order_event_is_ignored_but_a_new_status_is_stored(): void
    {
        $r = $this->recorder();
        $this->assertSame(1, $r->record('orders', self::orderEvent()));
        $this->assertSame(0, $r->record('orders', json_encode(self::orderEvent())));
        $this->assertSame(1, $r->record('orders', self::orderEvent(['status' => 'Done', 'eventTime' => 1759300009999, 'filledAmount' => '0.0001'])));

        $this->assertSame(2, ExchangeWsEvent::query()->count());
    }

    public function test_two_order_events_sharing_a_trade_id_are_both_stored(): void
    {
        $r = $this->recorder();
        $r->record('orders', self::orderEvent(['tradeId' => 55, 'status' => 'Active', 'eventTime' => 1]));
        $r->record('orders', self::orderEvent(['tradeId' => 55, 'status' => 'Canceled', 'eventTime' => 2]));

        $this->assertSame(2, ExchangeWsEvent::query()->count());
    }

    public function test_failed_variant_is_stored(): void
    {
        $failed = ['status' => 'Failed', 'code' => 'OverValueOrder', 'message' => 'too big', 'clientOrderId' => 'grid-x-9'];

        $this->assertSame(1, $this->recorder()->record('orders', json_encode($failed)));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertSame('Failed', $row->status);
        $this->assertNull($row->nobitex_order_id);
        $this->assertSame('grid-x-9', $row->client_order_id);
        $this->assertSame($failed, $row->payload);
    }

    /* ----------------------------------- trades ----------------------------------- */

    public function test_trade_event_is_stored_and_duplicate_ignored(): void
    {
        $r = $this->recorder();
        $this->assertSame(1, $r->record('trades', json_encode(self::tradeEvent())));
        $this->assertSame(0, $r->record('trades', self::tradeEvent()));
        $this->assertSame(1, $r->record('trades', self::tradeEvent(['id' => 777002])));

        $row = ExchangeWsEvent::query()->orderBy('id')->first();
        $this->assertSame('trades', $row->channel);
        $this->assertSame(777001, $row->trade_id);
        $this->assertSame(4567890123, $row->nobitex_order_id);
        $this->assertSame(1759300001000, $row->event_time_ms);
        $this->assertNull($row->status);
        $this->assertTrue($row->payload['isMaker']);
        $this->assertSame(2, ExchangeWsEvent::query()->count());
    }

    /* ---------------------------------- malformed --------------------------------- */

    public function test_malformed_events_are_not_stored_warn_and_never_throw(): void
    {
        $r = $this->recorder();
        foreach ([
            ['orders', 'not-json{'],
            ['orders', 42],
            ['orders', null],
            ['orders', ['status' => 'New']],          // no orderId, not Failed
            ['orders', ['orderId' => 'abc', 'status' => 'New']],
            ['trades', ['orderId' => 1]],             // no trade id
            ['trades', json_encode(['id' => 5])],     // no orderId
            ['bogus', self::orderEvent()],
        ] as [$channel, $data]) {
            $this->assertSame(0, $r->record($channel, $data));
        }

        $this->assertSame(0, ExchangeWsEvent::query()->count());
        $warnings = array_filter($this->logs, fn ($l) => $l['level'] === 'warning' && $l['message'] === 'WS_PRIVATE_EVENT_MALFORMED');
        $this->assertCount(8, $warnings);
    }

    public function test_storage_failure_is_swallowed(): void
    {
        Schema::drop('exchange_ws_events');

        $this->assertSame(0, $this->recorder()->record('orders', self::orderEvent()));
        $this->assertNotEmpty(array_filter($this->logs, fn ($l) => $l['message'] === 'WS_PRIVATE_EVENT_RECORD_FAILED'));
    }

    /* ---------------------------------- matching ---------------------------------- */

    public function test_matched_event_stores_match_and_local_status_without_touching_the_grid_order(): void
    {
        $gridId = $this->seedGridOrder('4567890123', 'placed');
        $before = (array) DB::table('grid_orders')->where('id', $gridId)->first();
        $botBefore = (array) DB::table('bot_configs')->first();

        $this->eloquentEvents = [];
        Event::listen('eloquent.*', function (string $name) {
            $this->eloquentEvents[] = $name;
        });

        $r = $this->recorder();
        $r->record('orders', self::orderEvent(['status' => 'Done', 'filledAmount' => '0.0001']));
        $r->record('trades', self::tradeEvent());

        $rows = ExchangeWsEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($gridId, $row->matched_grid_order_id);
            $this->assertSame('placed', $row->local_status_at_receipt);
        }

        // Read-only: grid order row byte-identical (updated_at included), and
        // no observer side effect on its bot.
        $this->assertSame($before, (array) DB::table('grid_orders')->where('id', $gridId)->first());
        $this->assertSame('2026-01-01 00:00:00', DB::table('grid_orders')->where('id', $gridId)->value('updated_at'));
        $this->assertSame($botBefore, (array) DB::table('bot_configs')->first());
        $this->assertSame([], array_values(array_filter(
            $this->eloquentEvents,
            fn ($e) => str_contains($e, 'GridOrder') || str_contains($e, 'BotConfig')
        )));

        $lines = array_values(array_filter($this->logs, fn ($l) => $l['message'] === 'WS_PRIVATE_EVENT'));
        $this->assertCount(2, $lines);
        $this->assertSame([
            'channel' => 'orders',
            'grid_order_id' => $gridId,
            'local_status' => 'placed',
            'event_status' => 'Done',
            'filledAmount' => '0.0001',
        ], $lines[0]['context']);
        $this->assertSame('trades', $lines[1]['context']['channel']);
    }

    public function test_duplicate_matched_event_does_not_log_twice(): void
    {
        $this->seedGridOrder('4567890123');
        $r = $this->recorder();
        $r->record('orders', self::orderEvent());
        $r->record('orders', self::orderEvent());

        $this->assertCount(1, array_filter($this->logs, fn ($l) => $l['message'] === 'WS_PRIVATE_EVENT'));
    }

    public function test_unknown_order_is_stored_with_null_match_and_no_event_log(): void
    {
        $this->seedGridOrder('111');

        $this->recorder()->record('orders', self::orderEvent(['orderId' => 222]));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertNull($row->matched_grid_order_id);
        $this->assertNull($row->local_status_at_receipt);
        $this->assertSame([], array_values(array_filter($this->logs, fn ($l) => $l['message'] === 'WS_PRIVATE_EVENT')));
    }

    /* ------------------------- secondary match by clientOrderId ------------------------- */

    /** A live intent row as the placement paths leave it before the REST response is saved. */
    private function seedIntentRow(?string $nobitexOrderId, string $clientOrderId, string $status = 'pending'): int
    {
        $botId = DB::table('bot_configs')->insertGetId([
            'name' => 'b', 'symbol' => 'BTCIRT', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        return DB::table('grid_orders')->insertGetId([
            'bot_config_id' => $botId,
            'price' => '9800000000',
            'amount' => '0.0001',
            'type' => 'buy',
            'status' => $status,
            'nobitex_order_id' => $nobitexOrderId,
            'client_order_id' => $clientOrderId,
            'role' => 'cycle_exit',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_event_arriving_before_the_rest_response_is_matched_by_client_order_id_read_only(): void
    {
        $gridId = $this->seedIntentRow(null, 'g9-55');
        $before = (array) DB::table('grid_orders')->where('id', $gridId)->first();

        $this->eloquentEvents = [];
        Event::listen('eloquent.*', function (string $name) {
            $this->eloquentEvents[] = $name;
        });

        $this->recorder()->record('orders', self::orderEvent(['clientOrderId' => 'g9-55', 'status' => 'Active']));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertSame($gridId, $row->matched_grid_order_id);
        $this->assertSame('pending', $row->local_status_at_receipt);

        // Read-only: the row is NOT given the exchange id here — that stays the
        // job of the placement path / reconciler.
        $this->assertSame($before, (array) DB::table('grid_orders')->where('id', $gridId)->first());
        $this->assertSame([], array_values(array_filter(
            $this->eloquentEvents,
            fn ($e) => str_contains($e, 'GridOrder') || str_contains($e, 'BotConfig')
        )));

        $lines = array_values(array_filter($this->logs, fn ($l) => $l['message'] === 'WS_PRIVATE_EVENT'));
        $this->assertCount(1, $lines);
        $this->assertSame($gridId, $lines[0]['context']['grid_order_id']);
    }

    public function test_failed_variant_is_matched_by_client_order_id(): void
    {
        $gridId = $this->seedIntentRow(null, 'g9-56');

        $this->recorder()->record('orders', ['status' => 'Failed', 'code' => 'OverValueOrder', 'message' => 'x', 'clientOrderId' => 'g9-56']);

        $row = ExchangeWsEvent::query()->sole();
        $this->assertNull($row->nobitex_order_id);
        $this->assertSame($gridId, $row->matched_grid_order_id);
    }

    public function test_client_order_id_never_matches_a_row_that_already_has_an_exchange_id(): void
    {
        // The row's stored exchange id disagrees with the event's orderId: the
        // clientOrderId must not override the primary key match.
        $this->seedIntentRow('999', 'g9-57', 'placed');

        $this->recorder()->record('orders', self::orderEvent(['clientOrderId' => 'g9-57']));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertNull($row->matched_grid_order_id);
        $this->assertNull($row->local_status_at_receipt);
    }

    public function test_nobitex_order_id_match_takes_precedence_over_client_order_id(): void
    {
        $primary   = $this->seedGridOrder('4567890123', 'placed');
        $secondary = $this->seedIntentRow(null, 'g9-58');

        $this->recorder()->record('orders', self::orderEvent(['clientOrderId' => 'g9-58']));

        $row = ExchangeWsEvent::query()->sole();
        $this->assertSame($primary, $row->matched_grid_order_id);
        $this->assertNotSame($secondary, $row->matched_grid_order_id);
    }

    /* ------------------------------------ prune ----------------------------------- */

    public function test_prune_deletes_only_rows_older_than_30_days(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $mk = fn (string $at, int $tradeId) => DB::table('exchange_ws_events')->insert([
            'channel' => 'trades', 'trade_id' => $tradeId, 'nobitex_order_id' => 1,
            'payload' => '{}', 'received_at' => $at,
        ]);
        $mk('2026-08-01 00:00:00', 1);   // 61 days
        $mk('2026-09-01 11:59:59', 2);   // just over 30 days
        $mk('2026-09-01 12:00:01', 3);   // just under 30 days
        $mk('2026-09-30 00:00:00', 4);

        $this->assertSame(2, ExchangeWsEvent::pruneOlderThan());

        $this->assertSame([3, 4], DB::table('exchange_ws_events')->orderBy('trade_id')->pluck('trade_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_prune_never_throws(): void
    {
        Schema::drop('exchange_ws_events');

        $this->assertNull(ExchangeWsEvent::pruneOlderThan());
    }

    public function test_migration_down_drops_the_table(): void
    {
        (require base_path(self::MIGRATION))->down();
        $this->assertFalse(Schema::hasTable('exchange_ws_events'));
    }
}
