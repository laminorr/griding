<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BotConfig;
use App\Models\GridOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessOrderEventJob (W4)
 * ------------------------------------------------------------------
 * Reacts to one of the user's private:orders WebSocket events right away
 * instead of waiting for the next CheckTradesJob minute. Dispatched by
 * ExchangeWsEventRecorder for a newly recorded, actionable, matched Spot event
 * — only when config('trading.websocket.act_on_private_events') is true
 * (env NOBITEX_WS_ACT_ON_EVENTS, default false).
 *
 * The event is only a TRIGGER. The exchange REST status is the truth: this job
 * calls CheckTradesJob::processSingleOrder(), which fetches the order's status
 * with the same NobitexService call the poller uses and runs the poller's own
 * processOrderStatus() / createPairOrder() code under the shared
 * "order-status:{id}" lock. The event's status/amounts/prices are never used
 * for state — $eventStatus and $eventTimeMs are carried for logging only.
 *
 * Uniqueness: ShouldBeUniqueUntilProcessing keyed by grid order id — a burst of
 * events for one order queues one job; an event arriving after that job has
 * started queues a new one (so a later fill is not lost).
 *
 * API-lag re-check: REST can lag the WS. A terminal event (Done / Canceled)
 * whose REST status is still non-terminal (ACTIVE, PENDING, ...) with nothing
 * paired is re-checked after trading.websocket.api_lag_recheck_delays (2s, 4s,
 * 8s) by DISPATCHING A NEW job carrying $recheck = attempt number, never by
 * release(): release() would spend $tries (kept for real failures) and is not
 * needed for uniqueness — ShouldBeUniqueUntilProcessing has already released
 * the unique lock before handle() runs, so the re-dispatch takes a fresh lock
 * (if a new event's job is already queued for this order, the re-dispatch is
 * absorbed and that job does the REST check instead). The delay is honoured by
 * the database queue (available_at = now + delay; `queue:work database
 * --sleep=1` polls every second). After the last delay: WS_EVENT_API_LAGGING_
 * GAVE_UP and the minute poller takes over. Each re-check goes through the
 * same guards as the first run (bot active, not simulation, order not terminal
 * locally) and the same order-status / pair-order locks, so one fill still
 * yields exactly one exit even when a re-check and the poller race.
 *
 * Safety net: the CheckTradesJob minute poller stays scheduled and enabled. If
 * this job is skipped, busy, delayed or fails, the next poll picks the order up
 * exactly as it did before W4.
 *
 * Roll back (no deploy): set NOBITEX_WS_ACT_ON_EVENTS=false, run
 * `php artisan config:clear`, and restart nobitex:ws-private (it read config at
 * boot), e.g. `pkill -f nobitex:ws-private` — its keepalive cron restarts it.
 * Already-queued jobs are harmless: each just re-checks one order via REST.
 */
class ProcessOrderEventJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Same retry/backoff shape as CheckTradesJob. */
    public $tries = 3;

    public $timeout = 60;

    public $backoff = [2, 4, 8];

    /**
     * Seconds the uniqueness lock may outlive a job that never starts (worker
     * down); after that a new event can queue again.
     */
    public $uniqueFor = 120;

    /**
     * WS event statuses that mean "the order is finished on the exchange".
     * ExchangeWsEventRecorder never dispatches the "Failed" placement variant
     * (not actionable), so it is not listed. "Inactive" (an untriggered stop)
     * is not terminal.
     */
    public const TERMINAL_EVENT_STATUSES = ['Done', 'Canceled'];

    /** REST statuses (GridOrderStatus values; Nobitex Inactive maps to CANCELED) with nothing left to wait for. */
    public const TERMINAL_API_STATUSES = ['FILLED', 'CANCELED', 'ERROR'];

    /** Local statuses a re-check can still change (CheckTradesJob::POLLED_STATUSES). */
    private const RECHECKABLE_LOCAL_STATUSES = ['placed', 'partially_filled'];

    public function __construct(
        public int $gridOrderId,
        public ?string $eventStatus = null,
        public ?int $eventTimeMs = null,
        /** 0 = the event itself; n = the n-th API-lag re-check. */
        public int $recheck = 0,
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->gridOrderId;
    }

    public function handle(): void
    {
        $order = GridOrder::find($this->gridOrderId);
        if (!$order) {
            $this->skip('order_missing');
            return;
        }

        $bot = BotConfig::find($order->bot_config_id);
        if (!$bot) {
            $this->skip('bot_missing', $order);
            return;
        }

        if ($bot->simulation) {
            $this->skip('simulation', $order);
            return;
        }

        // Same scope as the minute poller, which only processes active bots.
        if (!$bot->is_active) {
            $this->skip('bot_inactive', $order);
            return;
        }

        if (!$order->nobitex_order_id) {
            $this->skip('no_nobitex_order_id', $order);
            return;
        }

        // Nothing left to do locally: cancelled is terminal and never paired;
        // a filled order is done once its continuation exists.
        if ($order->status === 'cancelled'
            || ($order->status === 'filled' && $order->paired_order_id !== null)) {
            $this->skip('already_terminal', $order);
            return;
        }

        $result = (new CheckTradesJob())->processSingleOrder($order, $bot);

        $order->refresh();

        Log::channel('trading')->info('WS_EVENT_ACTED', [
            'grid_order_id'    => $order->id,
            'bot_id'           => $bot->id,
            'event_status'     => $this->eventStatus,
            'api_status_after' => $result['api_status'],
            'outcome'          => $result['outcome'],
            'local_status'     => $order->status,
            'pair_attempted'   => $result['pair_attempted'],
            'recheck'          => $this->recheck,
            'latency_ms'       => $this->eventTimeMs !== null
                ? (int) floor(microtime(true) * 1000) - $this->eventTimeMs
                : null,
        ]);

        if ($this->isApiLagging($result, $order)) {
            $this->scheduleRecheck($order, $bot, (string) $result['api_status']);
        }
    }

    /**
     * The WS said the order is finished, REST still says it is live, and
     * nothing was paired: the exchange's REST view is lagging its WS feed.
     * Not lagging: a non-terminal event (Active/partial), a busy lock or
     * missing status (api_status null: the poller has it), a terminal REST
     * status, a pair attempt, or a row already terminal locally.
     *
     * @param array{outcome:string, api_status:?string, pair_attempted:bool} $result
     */
    private function isApiLagging(array $result, GridOrder $order): bool
    {
        return in_array($this->eventStatus, self::TERMINAL_EVENT_STATUSES, true)
            && is_string($result['api_status'])
            && !in_array($result['api_status'], self::TERMINAL_API_STATUSES, true)
            && !$result['pair_attempted']
            && in_array($order->status, self::RECHECKABLE_LOCAL_STATUSES, true);
    }

    private function scheduleRecheck(GridOrder $order, BotConfig $bot, string $apiStatus): void
    {
        $delays = self::recheckDelays();
        if ($delays === []) {
            return; // re-checks disabled
        }

        if ($this->recheck >= count($delays)) {
            Log::channel('trading')->warning('WS_EVENT_API_LAGGING_GAVE_UP', [
                'grid_order_id' => $order->id,
                'bot_id'        => $bot->id,
                'event_status'  => $this->eventStatus,
                'api_status'    => $apiStatus,
                'rechecks'      => $this->recheck,
                'note'          => 'CheckTradesJob minute poller will pick this order up.',
            ]);
            return;
        }

        $attempt = $this->recheck + 1;
        $delay   = $delays[$this->recheck];

        // A NEW job (not release()): see the class docblock.
        $pending = self::dispatch($this->gridOrderId, $this->eventStatus, $this->eventTimeMs, $attempt)->delay($delay);
        unset($pending);

        Log::channel('trading')->info('WS_EVENT_API_LAG_RECHECK', [
            'grid_order_id' => $order->id,
            'bot_id'        => $bot->id,
            'event_status'  => $this->eventStatus,
            'api_status'    => $apiStatus,
            'attempt'       => $attempt,
            'delay'         => $delay,
        ]);
    }

    /** @return list<int> positive delays in seconds */
    public static function recheckDelays(): array
    {
        $raw = config('trading.websocket.api_lag_recheck_delays', [2, 4, 8]);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_filter(
            array_map(fn ($v) => (int) trim((string) $v), (array) $raw),
            fn (int $v) => $v > 0
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('trading')->error('WS_EVENT_JOB_FAILED', [
            'grid_order_id' => $this->gridOrderId,
            'event_status'  => $this->eventStatus,
            'error'         => $exception->getMessage(),
            'note'          => 'CheckTradesJob minute poller will pick this order up.',
        ]);
    }

    private function skip(string $reason, ?GridOrder $order = null): void
    {
        Log::channel('trading')->info('WS_EVENT_SKIPPED', [
            'grid_order_id' => $this->gridOrderId,
            'bot_id'        => $order?->bot_config_id,
            'local_status'  => $order?->status,
            'event_status'  => $this->eventStatus,
            'reason'        => $reason,
        ]);
    }
}
