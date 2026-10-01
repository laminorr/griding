<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessOrderEventJob;
use App\Models\ExchangeWsEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ExchangeWsEventRecorder
 * -----------------------
 * Records Nobitex private WebSocket events (private:orders / private:trades)
 * into exchange_ws_events. OBSERVE-ONLY (W3):
 *  - every well-formed event is stored (all market types, the "Failed" orders
 *    variant too) with insertOrIgnore, so reconnect replays never duplicate;
 *  - the event is matched to a grid order by nobitex_order_id with a plain
 *    query-builder SELECT on grid_orders — no Eloquent model is loaded, so
 *    nothing is saved and GridOrderObserver can never fire;
 *  - a matched, newly stored event logs one WS_PRIVATE_EVENT line (the W4
 *    yardstick: what the exchange said vs. what the bot believed);
 *  - malformed events are logged (warning) and skipped; nothing here throws.
 *
 * W4 (behind config('trading.websocket.act_on_private_events'), default false):
 * a NEWLY stored, matched, actionable Spot orders event additionally dispatches
 * ProcessOrderEventJob, which re-checks the order via REST through the
 * existing CheckTradesJob code. The event is only a trigger — nothing here
 * writes grid_orders. With the flag off, recording is exactly as in W3.
 */
class ExchangeWsEventRecorder
{
    /**
     * @param string $channel ExchangeWsEvent::CHANNEL_ORDERS|CHANNEL_TRADES
     * @param mixed  $data    push.pub.data — JSON string, a decoded event, or a list of events
     * @return int rows inserted (0 for duplicates/malformed)
     */
    public function record(string $channel, mixed $data): int
    {
        try {
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                if (!is_array($decoded)) {
                    $this->warn('WS_PRIVATE_EVENT_MALFORMED', $channel, 'data is not JSON');
                    return 0;
                }
                $data = $decoded;
            }

            if (!is_array($data) || $data === []) {
                $this->warn('WS_PRIVATE_EVENT_MALFORMED', $channel, 'data is not an object');
                return 0;
            }

            // Tolerate a batched list of events.
            $events = array_is_list($data) ? $data : [$data];

            $inserted = 0;
            foreach ($events as $event) {
                $inserted += $this->recordOne($channel, $event);
            }

            return $inserted;
        } catch (Throwable $e) {
            $this->warn('WS_PRIVATE_EVENT_RECORD_FAILED', $channel, $e->getMessage());
            return 0;
        }
    }

    private function recordOne(string $channel, mixed $event): int
    {
        if (!is_array($event) || $event === [] || array_is_list($event)) {
            $this->warn('WS_PRIVATE_EVENT_MALFORMED', $channel, 'event is not an object');
            return 0;
        }

        $row = match ($channel) {
            ExchangeWsEvent::CHANNEL_ORDERS => $this->orderRow($event),
            ExchangeWsEvent::CHANNEL_TRADES => $this->tradeRow($event),
            default => null,
        };

        if ($row === null) {
            $this->warn('WS_PRIVATE_EVENT_MALFORMED', $channel, 'missing/invalid required fields', [
                'keys' => array_slice(array_keys($event), 0, 30),
            ]);
            return 0;
        }

        $match = $row['nobitex_order_id'] !== null
            ? $this->findGridOrder($row['nobitex_order_id'])
            : null;

        $row['channel']                 = $channel;
        $row['payload']                 = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $row['matched_grid_order_id']   = $match?->id !== null ? (int) $match->id : null;
        $row['local_status_at_receipt'] = $match?->status !== null ? mb_substr((string) $match->status, 0, 32) : null;
        $row['received_at']             = now();

        $inserted = DB::table('exchange_ws_events')->insertOrIgnore($row);

        if ($inserted > 0 && $match !== null) {
            $this->log('info', 'WS_PRIVATE_EVENT', [
                'channel'       => $channel,
                'grid_order_id' => (int) $match->id,
                'local_status'  => $match->status,
                'event_status'  => $row['status'],
                'filledAmount'  => $channel === ExchangeWsEvent::CHANNEL_ORDERS
                    ? ($event['filledAmount'] ?? null)
                    : ($event['amount'] ?? null),
            ]);
        }

        if ($inserted > 0 && $match !== null && $channel === ExchangeWsEvent::CHANNEL_ORDERS) {
            $this->maybeDispatch((int) $match->id, $row, $event);
        }

        return $inserted;
    }

    /**
     * W4: queue a REST re-check of the matched grid order. Flag off → returns
     * before doing or logging anything. Never throws (the WS read loop must
     * survive a queue/DB outage; the minute poller is the safety net).
     *
     * @param array<string,mixed> $row   the stored exchange_ws_events row
     * @param array<string,mixed> $event the raw event
     */
    private function maybeDispatch(int $gridOrderId, array $row, array $event): void
    {
        if (!(bool) config('trading.websocket.act_on_private_events', false)) {
            return;
        }

        try {
            if (!$this->isActionable($row, $event)) {
                return;
            }

            // PendingDispatch pushes on destruct; unset() keeps that inside the try.
            $pending = ProcessOrderEventJob::dispatch($gridOrderId, $row['status'], $row['event_time_ms']);
            unset($pending);

            // "Requested": if a job for this order is already queued and not yet
            // started, ShouldBeUniqueUntilProcessing absorbs this one.
            $this->log('info', 'WS_EVENT_DISPATCH_REQUESTED', [
                'grid_order_id' => $gridOrderId,
                'event_status'  => $row['status'],
                'event_time_ms' => $row['event_time_ms'],
            ]);
        } catch (Throwable $e) {
            $this->warn('WS_EVENT_DISPATCH_FAILED', ExchangeWsEvent::CHANNEL_ORDERS, $e->getMessage(), [
                'grid_order_id' => $gridOrderId,
            ]);
        }
    }

    /**
     * Actionable = the order may have changed in a way the poller acts on:
     * Done, Canceled, Inactive, or Active with filledAmount > 0 (a partial).
     * New / zero-fill Active, Failed and non-Spot events are ignored.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $event
     */
    private function isActionable(array $row, array $event): bool
    {
        if ($row['market_type'] !== 'Spot') {
            return false;
        }

        return match ($row['status']) {
            'Done', 'Canceled', 'Inactive' => true,
            'Active' => $this->isPositiveAmount($event['filledAmount'] ?? null),
            default => false,
        };
    }

    private function isPositiveAmount(mixed $v): bool
    {
        if (is_int($v) || is_float($v)) {
            $v = Money::normalize($v); // a JSON number; fixed-point, never "1.0E-5"
        }
        if (!is_string($v)) {
            return false; // null/bool/arrays: not an amount
        }
        $s = trim($v);
        if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $s) !== 1) {
            return false;
        }

        return Money::isPositive(Money::normalize($s));
    }

    /** @return array<string,mixed>|null */
    private function orderRow(array $e): ?array
    {
        $status = isset($e['status']) && is_string($e['status']) ? $e['status'] : null;
        $orderId = $this->uint($e['orderId'] ?? null);

        // Failure variant: {"status":"Failed","code":...,"message":...,"clientOrderId":...}
        if ($orderId === null && $status !== 'Failed') {
            return null;
        }

        return [
            'nobitex_order_id' => $orderId,
            'trade_id'         => null, // orders' tradeId stays in payload (see migration)
            'client_order_id'  => $this->str($e['clientOrderId'] ?? null, 64),
            'status'           => $this->str($status, 16),
            'market_type'      => $this->str($e['marketType'] ?? null, 16),
            'event_time_ms'    => $this->uint($e['eventTime'] ?? null),
        ];
    }

    /** @return array<string,mixed>|null */
    private function tradeRow(array $e): ?array
    {
        $tradeId = $this->uint($e['id'] ?? null);
        $orderId = $this->uint($e['orderId'] ?? null);
        if ($tradeId === null || $orderId === null) {
            return null;
        }

        return [
            'nobitex_order_id' => $orderId,
            'trade_id'         => $tradeId,
            'client_order_id'  => null,
            'status'           => null,
            'market_type'      => null,
            // `time` (ms); the deprecated `timestamp` field is ignored.
            'event_time_ms'    => $this->uint($e['time'] ?? null),
        ];
    }

    /**
     * READ-ONLY lookup. grid_orders.nobitex_order_id is a VARCHAR holding the
     * exchange's integer id, so the int is normalised to its decimal string.
     */
    private function findGridOrder(int $nobitexOrderId): ?object
    {
        return DB::table('grid_orders')
            ->where('nobitex_order_id', (string) $nobitexOrderId)
            ->orderByDesc('id')
            ->first(['id', 'status']);
    }

    /** Non-negative integer from int or digit-string; null otherwise. */
    private function uint(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 ? $v : null;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v) && strlen($v) <= 18) {
            return (int) $v;
        }
        return null;
    }

    private function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return null;
        }
        $s = (string) $v;
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function warn(string $msg, string $channel, string $reason, array $ctx = []): void
    {
        $this->log('warning', $msg, ['channel' => $channel, 'reason' => $reason] + $ctx);
    }

    private function log(string $level, string $msg, array $ctx): void
    {
        try {
            Log::channel('nobitex')->{$level}($msg, $ctx);
        } catch (Throwable) {
            // Logging must never break the read loop.
        }
    }
}
