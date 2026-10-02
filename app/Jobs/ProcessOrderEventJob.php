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

    public function __construct(
        public int $gridOrderId,
        public ?string $eventStatus = null,
        public ?int $eventTimeMs = null,
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
            'latency_ms'       => $this->eventTimeMs !== null
                ? (int) floor(microtime(true) * 1000) - $this->eventTimeMs
                : null,
        ]);
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
