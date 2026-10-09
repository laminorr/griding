<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\BotActivityLog;
use App\Models\BotConfig;
use App\Models\CompletedTrade;
use App\Models\GridOrder;
use App\Services\CandleService;
use App\Services\FeeModel;
use App\Services\NobitexService;
use App\Support\MarketPrecision;
use App\Support\Money;
use App\Support\QtyPrecision;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Forensic, READ-ONLY audit of one bot (`php artisan bot:audit`).
 *
 * Reads: bot_configs, grid_orders, completed_trades, bot_activity_logs,
 * exchange_ws_events (if present), the log files under storage/logs (plain
 * and .gz), the cache (precision maps, last price) — never writes any of them.
 * Exchange: only the existing signed reads NobitexService::getOrdersStatus()
 * (one order per call) and getBalances(), plus the public OHLC history, each
 * call separated by >= rate_ms (default 300 ms); all skipped by no_exchange.
 * Deliberately NOT used because they write: MarketPrecision::priceTick()
 * (Cache::add on drift), FeeModel::classifyActualFee() (Cache::add),
 * CandleService::getCandles() (Cache::put).
 *
 * All money math is bcmath via App\Support\Money. Every number in the result
 * is either a FACT (computed from rows/logs/exchange, with its source) or
 * sits under a key/section explicitly labelled ESTIMATE.
 */
final class BotAuditor
{
    public const OPEN_STATUSES = ['placed', 'partially_filled', 'pending', 'submission_unknown'];

    public const RESOLUTIONS = [
        '1m' => ['1', 60], '5m' => ['5', 300], '15m' => ['15', 900], '30m' => ['30', 1800],
        '1h' => ['60', 3600], '3h' => ['180', 10800], '4h' => ['240', 14400], '6h' => ['360', 21600],
        '12h' => ['720', 43200], '1d' => ['D', 86400],
    ];

    private array $anomalies = [];
    private int $httpCalls = 0;
    private bool $firstCall = true;

    /** @var array<int,array> raw grid_orders rows by id (every row of the bot) */
    private array $byId = [];
    /** @var array<int,list<array>> cycle_exit rows by parent id */
    private array $exitsByParent = [];
    private array $logs = [];
    private array $botLogs = [];
    private array $opt = [];
    private ?BotConfig $bot = null;
    private string $symbol = 'BTCIRT';
    private int $tick = 10;
    private int $qtyDec = 8;
    private string $spacing = '0';
    private string $from = '';

    public function __construct(
        private readonly NobitexService $nobitex,
        private readonly FeeModel $fees,
        private readonly AuditLogScanner $scanner,
        private readonly GridReplaySimulator $simulator,
    ) {}

    /**
     * @param array{from:?string, to:?string, start_rls:?string, start_btc:?string, no_exchange:bool, candles:string, log_dir:string, synced:list<int>, rate_ms:int} $opt
     */
    public function audit(int $botId, array $opt): array
    {
        $this->opt = $opt + ['rate_ms' => 300, 'synced' => [], 'candles' => '1h', 'no_exchange' => false];
        $this->opt['rate_ms'] = max(300, (int) $this->opt['rate_ms']);

        $bot = BotConfig::find($botId);
        if ($bot === null) {
            throw new \InvalidArgumentException("Bot {$botId} not found.");
        }
        $this->bot     = $bot;
        $this->symbol  = QtyPrecision::canonicalSymbol((string) ($bot->symbol ?: 'BTCIRT'));
        $this->spacing = Money::div(self::dec($bot->getAttributes()['grid_spacing'] ?? '0'), '100');

        $rows = GridOrder::where('bot_config_id', $botId)->orderBy('id')->get()
            ->map(fn (GridOrder $o) => self::raw($o->getAttributes()))->all();
        foreach ($rows as $r) {
            $this->byId[(int) $r['id']] = $r;
            if ($this->isExit($r)) {
                $this->exitsByParent[(int) $r['paired_order_id']][] = $r;
            }
        }

        [$from, $to] = $this->window($rows);
        $precision   = $this->precision();

        // Logs are read from 15 min before the window so the GRID_PLAN /
        // budget lines that precede the first build are attributable; §8
        // counts stay strictly inside the window.
        $this->from = $from;
        $logFrom    = Carbon::parse($from, (string) config('app.timezone'))->subMinutes(15)->format('Y-m-d H:i:s');
        $this->logs = $this->scanner->scan((string) $this->opt['log_dir'], $logFrom, $to, self::keepLogLine(...));
        $this->indexBotLogs();

        $synced = $this->syncedRows();

        $A = [
            'meta' => [
                'bot_id'          => $botId,
                'generated_at'    => now()->format('Y-m-d H:i:s'),
                'timezone'        => (string) config('app.timezone'),
                'window'          => ['from' => $from, 'to' => $to, 'from_source' => $this->opt['from'] ? '--from' : 'first order / started_at', 'to_source' => $this->opt['to'] ? '--to' : 'now'],
                'options'         => array_diff_key($this->opt, ['log_dir' => 1]) + ['log_dir' => $this->opt['log_dir']],
                'symbol'          => $this->symbol,
                'precision'       => $precision,
                'spacing_fraction'=> $this->spacing,
                'fees'            => [
                    'buy_bps'           => $this->fees->rateFor($bot, 'buy'),
                    'sell_bps'          => $this->fees->rateFor($bot, 'sell'),
                    'buy_currency'      => $this->fees->expectedCurrency('buy'),
                    'sell_currency'     => $this->fees->expectedCurrency('sell'),
                    'drift_warn_bps'    => (string) config('trading.fees.drift_warn_bps', '5'),
                    'break_even'        => $this->fees->breakEvenSpacing($bot),
                ],
                'log_files'       => $this->scanner->files,
                'log_lines_in_window' => count(array_filter($this->logs, fn ($e) => $e['ts'] >= $from)),
                'log_lines_read' => $this->scanner->linesInWindow,
                'log_lines_testing_skipped' => $this->scanner->skippedTesting,
                'synced_rows'     => $synced,
            ],
        ];

        $A['timeline']  = $this->timeline($rows, $from, $to);
        $A['orders']    = $this->orderLedger($rows, $from, $to, $synced);
        $A['exchange']  = $this->exchangeRecon($rows, $synced);
        $A['cycles']    = $this->cycles($from, $to);
        $A['dust']      = $this->dustReplay($rows);
        $A['market']    = $this->market($rows, $from, $to, $A['timeline']['builds'], $A['cycles']);
        $A['balance']   = $this->balance($rows, $from, $A['exchange']['wallet'] ?? null);
        $A['position']  = $this->position($rows, $A['cycles'], $A['balance'], $A['market']);
        $A['infra']     = $this->infra();
        $A['events']    = $this->events($rows, $from, $to);
        $A['meta']['http_calls'] = $this->httpCalls;

        $A['anomalies'] = $this->sortedAnomalies();
        $A['opinion']   = $this->opinion($A);
        $A['summary']   = $this->summary($A);

        return $A;
    }

    /* ================================================================== */
    /* Window, precision, logs                                             */
    /* ================================================================== */

    private function window(array $rows): array
    {
        $tz = (string) config('app.timezone');
        if ($this->opt['from']) {
            $from = Carbon::parse((string) $this->opt['from'], $tz)->format('Y-m-d H:i:s');
        } else {
            $cands = array_filter([
                $rows !== [] ? min(array_column($rows, 'created_at')) : null,
                $this->bot->getAttributes()['started_at'] ?? null,
            ]);
            $from = $cands !== [] ? (string) min($cands) : now()->subDays(7)->format('Y-m-d H:i:s');
        }
        $to = $this->opt['to']
            ? Carbon::parse((string) $this->opt['to'], $tz)->format('Y-m-d H:i:s')
            : now()->format('Y-m-d H:i:s');

        return [$from, $to];
    }

    /** Tick / qty decimals from the precision CACHE (read only) → config. Never fetches. */
    private function precision(): array
    {
        $read = function (string $key) {
            try {
                return Cache::get($key);
            } catch (\Throwable) {
                return null;
            }
        };
        $live = $read(MarketPrecision::LIVE_CACHE_KEY);
        $lkg  = $read(MarketPrecision::LKG_CACHE_KEY);

        $pick = function (string $field, ?int $cfg, int $default) use ($live, $lkg): array {
            if (is_array($live) && is_int($live[$field][$this->symbol] ?? null)) {
                return [$live[$field][$this->symbol], 'cache:live'];
            }
            if (is_array($lkg) && is_int($lkg[$field][$this->symbol] ?? null)) {
                return [$lkg[$field][$this->symbol], 'cache:last_known_good'];
            }
            return $cfg !== null ? [$cfg, 'config'] : [$default, 'default'];
        };

        $cfgTick = config("trading.ticks.{$this->symbol}");
        $cfgQty  = config("trading.exchange.precision.{$this->symbol}.qty_decimals");
        [$this->tick, $tickSrc]  = $pick('tick', $cfgTick === null ? null : max(1, (int) $cfgTick), MarketPrecision::DEFAULT_TICK);
        [$this->qtyDec, $qtySrc] = $pick('qty', $cfgQty === null ? null : (int) $cfgQty, MarketPrecision::DEFAULT_QTY_DECIMALS);

        return ['tick' => $this->tick, 'tick_source' => $tickSrc, 'qty_decimals' => $this->qtyDec, 'qty_source' => $qtySrc];
    }

    /** INFO/DEBUG events the audit reads; every WARNING+ line is kept for §8. */
    private const KEEP_INFO = [
        'EXIT_SIZED', 'EXIT_BUY_DUST_SETTLED', 'EXIT_DUST_REVERTED', 'EXIT_DUST_DEFERRED', 'PARTIAL_FILL_DUSTED',
        'WS_EVENT_ACTED', 'WS_EVENT_SKIPPED', 'GRID_PLAN', 'REBALANCE_EFFECTIVE_BUDGET', 'ADJUST_GRID_BOT_COMPLETE',
        'ADJUST_GRID_BOT_SKIP_KILLED', 'SKIP_PAIR_KILLED', 'PRECISION_ROW_SYNCED', 'PAIR_ORDER_PRE_CREATE',
        'PAIR_ORDER_POST_CREATE', 'PAIR_ORDER_ALREADY_PAIRED', 'EXIT_BLOCK_CLEARED', 'EXIT_SELF_HEALED',
        'RECONCILE_RESOLVED_PLACED', 'RECONCILE_RESOLVED_CANCELLED', 'FEES_BACKFILL', 'WS_EVENT_API_LAG_RECHECK',
    ];

    public static function keepLogLine(array $e): bool
    {
        return ! in_array($e['level'], ['INFO', 'DEBUG', 'NOTICE'], true)
            || in_array($e['event'], self::KEEP_INFO, true)
            || str_starts_with($e['event'], 'KILL_SWITCH')
            || str_starts_with($e['message'], '[WS-PRIVATE] Connect');
    }

    /** Log entries that belong to this bot (by bot_id, or by one of its row / exchange ids). */
    private function indexBotLogs(): void
    {
        $botId  = (int) $this->bot->id;
        $rowIds = array_fill_keys(array_map('strval', array_keys($this->byId)), true);
        $exIds  = [];
        foreach ($this->byId as $r) {
            if (! empty($r['nobitex_order_id'])) {
                $exIds[(string) $r['nobitex_order_id']] = true;
            }
        }
        $rowKeys = ['grid_order_id', 'filled_order_id', 'exit_order_id', 'buy_order_id', 'sell_order_id', 'order_id'];

        foreach ($this->logs as $i => $e) {
            $c = $e['context'];
            if (isset($c['bot_id']) && is_scalar($c['bot_id'])) {
                if ((int) $c['bot_id'] === $botId) {
                    $this->botLogs[] = $i;
                }
                continue;
            }
            foreach ($rowKeys as $k) {
                if (isset($c[$k]) && is_scalar($c[$k]) && (isset($rowIds[(string) $c[$k]]) || isset($exIds[(string) $c[$k]]))) {
                    $this->botLogs[] = $i;
                    continue 2;
                }
            }
            if (isset($c['nobitex_order_id']) && is_scalar($c['nobitex_order_id']) && isset($exIds[(string) $c['nobitex_order_id']])) {
                $this->botLogs[] = $i;
            }
        }
    }

    /** @return list<array> bot-related log entries with the given event name(s) */
    private function botEvents(string ...$events): array
    {
        $want = array_flip($events);
        $out  = [];
        foreach ($this->botLogs as $i) {
            if (isset($want[$this->logs[$i]['event']])) {
                $out[] = $this->logs[$i];
            }
        }
        return $out;
    }

    /** @return array<int,list<array>> bot events keyed by an integer context field */
    private function eventsBy(string $event, string $field): array
    {
        $out = [];
        foreach ($this->botEvents($event) as $e) {
            if (isset($e['context'][$field]) && is_scalar($e['context'][$field])) {
                $out[(int) $e['context'][$field]][] = $e;
            }
        }
        return $out;
    }

    /** Rows repaired by grid:sync-precision: PRECISION_ROW_SYNCED log lines + --synced. */
    private function syncedRows(): array
    {
        $ids = array_map('intval', (array) $this->opt['synced']);
        foreach ($this->botEvents('PRECISION_ROW_SYNCED') as $e) {
            $ids[] = (int) ($e['context']['grid_order_id'] ?? 0);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    /* ================================================================== */
    /* §1 Bot & timeline                                                   */
    /* ================================================================== */

    private function timeline(array $rows, string $from, string $to): array
    {
        $cfg = $this->bot->getAttributes();
        ksort($cfg);

        // Builds: grid (non-exit) rows clustered by creation time and role.
        $grid = array_values(array_filter($rows, fn ($r) => ! $this->isExit($r)));
        usort($grid, fn ($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']) ?: $a['id'] <=> $b['id']);
        $builds = [];
        foreach ($grid as $r) {
            $last = $builds === [] ? null : $builds[count($builds) - 1];
            $role = (string) ($r['role'] ?? 'legacy_null');
            if ($last === null || $last['role'] !== $role || $this->secs($last['last_at'], $r['created_at']) > 120) {
                $builds[] = ['role' => $role, 'at' => $r['created_at'], 'last_at' => $r['created_at'], 'row_ids' => [], 'buys' => 0, 'sells' => 0, 'notional' => '0', 'prices' => [], 'orders' => []];
            }
            $b = &$builds[count($builds) - 1];
            $b['last_at']  = $r['created_at'];
            $b['row_ids'][] = (int) $r['id'];
            $r['type'] === 'buy' ? $b['buys']++ : $b['sells']++;
            $amt = self::dec($r['original_amount'] ?? $r['amount']);
            $b['notional'] = Money::add($b['notional'], Money::mul(self::dec($r['price']), $amt));
            $b['prices'][] = $r['type'] . '@' . self::dec($r['price']);
            $b['orders'][] = ['side' => (string) $r['type'], 'price' => self::dec($r['price']), 'amount' => $amt];
            unset($b);
        }

        // Budget used by each build (REBALANCE_EFFECTIVE_BUDGET has bot_id; GRID_PLAN
        // carries no bot id and is attributed by symbol + time proximity).
        $budgetEvents = $this->botEvents('REBALANCE_EFFECTIVE_BUDGET');
        $plans = array_values(array_filter($this->logs, fn ($e) => $e['event'] === 'GRID_PLAN'
            && QtyPrecision::canonicalSymbol((string) ($e['context']['symbol'] ?? '')) === $this->symbol));
        foreach ($builds as &$b) {
            $b['notional'] = FeeModel::roundHalfUp($b['notional'], 0);
            $b['effective_budget'] = null;
            foreach ($budgetEvents as $e) {
                $d = $this->secs($e['ts'], $b['at']);
                if ($d >= 0 && $d <= 300) {
                    $b['effective_budget'] = $e['context'] + ['logged_at' => $e['ts']];
                }
            }
            $b['grid_plan'] = null;
            foreach ($plans as $e) {
                $d = $this->secs($e['ts'], $b['at']);
                if ($d >= 0 && $d <= 300) {
                    $ctx = $e['context'];
                    $b['grid_plan'] = [
                        'logged_at' => $e['ts'], 'mid' => $ctx['mid'] ?? null, 'budget_irt' => $ctx['budget_irt'] ?? null,
                        'levels' => $ctx['levels'] ?? null, 'step_pct' => $ctx['step_pct'] ?? null,
                        'estimated_notional' => $ctx['estimated_notional'] ?? null, 'below_min_orders' => $ctx['below_min_orders'] ?? null,
                        'attribution' => 'symbol + logged <=300s before the build (GRID_PLAN has no bot_id)',
                    ];
                }
            }
        }
        unset($b);

        $risk = [];
        foreach ($this->botLogs as $i) {
            $e = $this->logs[$i];
            if (preg_match('/KILL_SWITCH|STOP_LOSS|DRAWDOWN|SKIP_PAIR_KILLED|SKIP_KILLED/i', $e['event'])
                || preg_match('/stop_loss|drawdown/i', $e['message'])) {
                $risk[] = ['ts' => $e['ts'], 'level' => $e['level'], 'event' => $e['event'], 'context' => $e['context']];
            }
        }
        foreach ($risk as $r) {
            if ($r['event'] === 'KILL_SWITCH_TRIGGERED') {
                $this->flag('critical', 'KILL_SWITCH', "Kill switch triggered at {$r['ts']} (" . ($r['context']['reason'] ?? '?') . ')', [], $r['ts']);
            }
        }

        $applied = array_values(array_filter($this->botEvents('ADJUST_GRID_BOT_COMPLETE'), fn ($e) => ! empty($e['context']['rebalance_applied'])));

        $firstOrder = $rows !== [] ? (string) min(array_column($rows, 'created_at')) : null;
        $active     = $this->activeBudget();

        return [
            'config'              => $cfg,
            'active_budget_irt'   => $active,
            'first_order_at'      => $firstOrder,
            'started_at'          => $cfg['started_at'] ?? null,
            'stopped_at'          => $cfg['stopped_at'] ?? null,
            'stop_reason'         => $cfg['stop_reason'] ?? null,
            'is_active'           => (bool) ($cfg['is_active'] ?? false),
            'builds'              => array_map(fn ($b) => array_diff_key($b, ['last_at' => 1]), $builds),
            'rebalances_applied_logged' => array_map(fn ($e) => $e['ts'], $applied),
            'budget_events'       => array_map(fn ($e) => ['ts' => $e['ts']] + $e['context'], $budgetEvents),
            'risk_events'         => $risk,
            'activity_log_counts' => $this->activityCounts($from, $to),
        ];
    }

    private function activityCounts(string $from, string $to): array
    {
        try {
            return BotActivityLog::where('bot_config_id', $this->bot->id)
                ->whereBetween('created_at', [$from, $to])
                ->select('action_type', 'level', DB::raw('count(*) as n'))
                ->groupBy('action_type', 'level')->get()
                ->map(fn ($r) => ['action_type' => $r->action_type, 'level' => $r->level, 'n' => (int) $r->n])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /* ================================================================== */
    /* §2 Order ledger                                                     */
    /* ================================================================== */

    private function orderLedger(array $rows, string $from, string $to, array $synced): array
    {
        $wsActed   = $this->eventsBy('WS_EVENT_ACTED', 'grid_order_id');
        $exitSized = $this->eventsBy('EXIT_SIZED', 'filled_order_id');
        $haveLogs  = $this->logs !== [];
        $syncedSet = array_flip($synced);
        $buyBps    = $this->fees->rateFor($this->bot, 'buy');
        $sellBps   = $this->fees->rateFor($this->bot, 'sell');
        $driftWarn = (string) config('trading.fees.drift_warn_bps', '5');
        $botActive = (bool) ($this->bot->getAttributes()['is_active'] ?? false);

        $out = [];
        foreach ($rows as $r) {
            $id      = (int) $r['id'];
            $side    = (string) $r['type'];
            $price   = self::dec($r['price']);
            $amount  = self::dec($r['amount']);
            $filled  = self::dec($r['filled_amount']);
            $avg     = $this->fillPrice($r);
            $isExit  = $this->isExit($r);
            $parent  = $isExit ? ($this->byId[(int) $r['paired_order_id']] ?? null) : null;
            $checks  = [];
            $inWin   = ((string) $r['created_at'] >= $from && (string) $r['created_at'] <= $to)
                || ($r['filled_at'] && (string) $r['filled_at'] >= $from && (string) $r['filled_at'] <= $to);
            $isSynced = isset($syncedSet[$id]);

            // (a) price on the market tick
            $onTick = Money::compare($price, (string) MarketPrecision::alignToTick($price, (string) $this->tick, false)) === 0;
            $checks['a_tick'] = $onTick ? 'ok' : "off-tick (tick {$this->tick})";
            if (! $onTick) {
                $this->flag($isSynced ? 'info' : 'warning', 'PRICE_OFF_TICK', "Row {$id} price {$price} is not on the {$this->tick}-rial tick" . ($isSynced ? ' (row later synced by grid:sync-precision)' : ''), [$id]);
            }

            // (b) amount on the quantity step
            $onStep = Money::compare($amount, Money::floorToScale($amount, $this->qtyDec)) === 0;
            $checks['b_step'] = $onStep ? 'ok' : "off-step ({$this->qtyDec} dp)";
            if (! $onStep) {
                $this->flag($isSynced || $this->isTerminal($r) ? 'info' : 'warning', 'AMOUNT_OFF_STEP',
                    "Row {$id} amount {$amount} has more than {$this->qtyDec} decimals" . ($this->isTerminal($r) ? ' (historic: row is ' . $r['status'] . '; the exchange truncates)' : ''), [$id]);
            }

            // (c) exit price recomputed from the parent
            $expectedExit = null;
            $expectedFromFill = null;
            $checks['c_exit_price'] = 'n/a';
            if ($isExit) {
                if ($parent === null) {
                    $checks['c_exit_price'] = 'parent row missing';
                    $this->flag('warning', 'EXIT_PARENT_MISSING', "Exit row {$id} points to missing parent {$r['paired_order_id']}", [$id]);
                } else {
                    $expectedExit     = $this->exitPrice(self::dec($parent['price']), $side);
                    $expectedFromFill = $this->exitPrice($this->fillPrice($parent), $side);
                    if (Money::compare($expectedExit, $price) === 0) {
                        $checks['c_exit_price'] = 'ok';
                    } else {
                        $checks['c_exit_price'] = "expected {$expectedExit}, row has {$price}";
                        $this->flag($isSynced ? 'info' : 'warning', 'EXIT_PRICE_MISMATCH',
                            "Exit {$id}: price {$price} ≠ parent {$parent['id']} limit {$parent['price']} × (1 " . ($side === 'sell' ? '+' : '−') . " spacing) side-safe = {$expectedExit}" . ($isSynced ? ' (row synced by grid:sync-precision: historic)' : ''), [$id, (int) $parent['id']]);
                    }
                    if (Money::compare($expectedFromFill, $expectedExit) !== 0) {
                        $checks['c_exit_price'] .= "; from avg fill it would be {$expectedFromFill}";
                    }
                    if ((string) $parent['type'] === $side) {
                        $this->flag('critical', 'EXIT_SAME_SIDE', "Exit {$id} has the same side as its parent {$parent['id']}", [$id, (int) $parent['id']]);
                    }
                    if ($this->isExit($parent)) {
                        $this->flag('warning', 'EXIT_OF_EXIT', "Exit {$id} was created for another exit ({$parent['id']}) — the bot should not re-arm exits", [$id, (int) $parent['id']]);
                    }
                }
            }

            // (d) exactly one exit per fill
            $exits = $this->exitsByParent[$id] ?? [];
            $liveExits = array_values(array_filter($exits, fn ($x) => ! ((string) $x['status'] === 'cancelled' && ! Money::isPositive(self::dec($x['filled_amount'])))));
            if (! $isExit && $this->hasExecution($r)) {
                if (count($liveExits) > 1) {
                    $checks['d_one_exit'] = 'DUPLICATE: exits ' . implode(',', array_column($liveExits, 'id'));
                    $this->flag('critical', 'DUPLICATE_EXIT', "Fill {$id} has " . count($liveExits) . ' live exits (' . implode(', ', array_column($liveExits, 'id')) . ') — double inventory exposure', array_merge([$id], array_map('intval', array_column($liveExits, 'id'))));
                } elseif (count($liveExits) === 1) {
                    $checks['d_one_exit'] = 'ok (exit ' . $liveExits[0]['id'] . ')';
                    if ((int) ($r['paired_order_id'] ?? 0) !== (int) $liveExits[0]['id']) {
                        $this->flag('warning', 'BACKLINK_MISMATCH', "Fill {$id}.paired_order_id = " . ($r['paired_order_id'] ?? 'null') . " but its exit is {$liveExits[0]['id']}", [$id, (int) $liveExits[0]['id']]);
                    }
                } elseif ((string) ($r['exit_state'] ?? '') === 'dusted') {
                    $checks['d_one_exit'] = 'absorbed into dust';
                } elseif ((string) ($r['exit_state'] ?? '') === 'blocked') {
                    $checks['d_one_exit'] = 'EXIT BLOCKED: ' . ($r['exit_blocked_reason'] ?? '');
                    $this->flag('critical', 'EXIT_BLOCKED_ROW', "Fill {$id} has a blocked exit: " . ($r['exit_blocked_reason'] ?? '?'), [$id]);
                } else {
                    $checks['d_one_exit'] = 'NO EXIT';
                    $this->flag($botActive ? 'critical' : 'warning', 'FILL_WITHOUT_EXIT', "Fill {$id} ({$side} {$filled} @ {$avg}) has no exit order" . ($botActive ? '' : ' (bot inactive)'), [$id]);
                }
                if (count($exits) > count($liveExits)) {
                    $checks['d_one_exit'] .= '; cancelled exit intents: ' . implode(',', array_column(array_udiff($exits, $liveExits, fn ($a, $b) => $a['id'] <=> $b['id']), 'id'));
                }
            } else {
                $checks['d_one_exit'] = $isExit ? 'n/a (exit)' : 'n/a (no fill)';
            }

            // (e) fee currency by side, (f) effective fee rate
            $effBps = null;
            if ($r['fee_amount'] !== null && $r['fee_currency'] !== null && Money::isPositive($filled)) {
                $expectedCur = $side === 'buy' ? $this->fees->expectedCurrency('buy') : $this->fees->expectedCurrency('sell');
                $checks['e_fee_currency'] = $r['fee_currency'] === $expectedCur ? 'ok' : "{$r['fee_currency']} (expected {$expectedCur})";
                if ($r['fee_currency'] !== $expectedCur) {
                    $this->flag('warning', 'FEE_CURRENCY', "Row {$id} ({$side}) fee charged in {$r['fee_currency']}, expected {$expectedCur}", [$id]);
                }
                $fee   = self::dec($r['fee_amount']);
                $base  = $r['fee_currency'] === 'base' ? $filled : Money::mul($filled, $avg);
                $effBps = Money::isPositive($base) ? FeeModel::roundHalfUp(Money::mul(Money::div($fee, $base), '10000'), 4) : null;
                $cfgBps = $side === 'buy' ? $buyBps : $sellBps;
                if ($effBps === null) {
                    $checks['f_fee_rate'] = 'n/a';
                } elseif ((string) $r['fee_source'] !== 'actual') {
                    $checks['f_fee_rate'] = "{$effBps} bps (ESTIMATED fee — not verifiable)";
                } else {
                    $drift = Money::abs(Money::sub($effBps, $cfgBps));
                    $checks['f_fee_rate'] = "{$effBps} bps vs {$cfgBps}";
                    if (Money::compare($drift, $driftWarn) > 0) {
                        $checks['f_fee_rate'] .= ' DRIFT';
                        $this->flag('warning', 'FEE_RATE_DRIFT', "Row {$id} ({$side}) effective fee {$effBps} bps vs configured {$cfgBps} bps (drift {$drift} > {$driftWarn})", [$id]);
                    }
                }
            } else {
                $checks['e_fee_currency'] = Money::isPositive($filled) ? 'no fee stored' : 'n/a';
                $checks['f_fee_rate'] = 'n/a';
                if (Money::isPositive($filled)) {
                    $this->flag('info', 'FEE_NOT_STORED', "Row {$id} has a fill but no stored fee (pre-fee-model row?)", [$id]);
                }
            }

            // (g) sell above its paired buy
            if ($isExit && $parent !== null && (string) $parent['type'] !== $side) {
                [$buy, $sell] = $side === 'sell' ? [$parent, $r] : [$r, $parent];
                $bp = $this->hasExecution($buy) ? $this->fillPrice($buy) : self::dec($buy['price']);
                $sp = $this->hasExecution($sell) ? $this->fillPrice($sell) : self::dec($sell['price']);
                $ok = Money::compare($sp, $bp) > 0;
                $checks['g_sell_above_buy'] = $ok ? 'ok' : "sell {$sp} <= buy {$bp}";
                if (! $ok) {
                    $this->flag('critical', 'SELL_NOT_ABOVE_BUY', "Cycle {$buy['id']}/{$sell['id']}: sell {$sp} is not above buy {$bp}", [(int) $buy['id'], (int) $sell['id']]);
                }
            } else {
                $checks['g_sell_above_buy'] = 'n/a';
            }

            if (Money::compare($filled, $amount) > 0 && Money::compare($filled, self::dec($r['original_amount'] ?? $r['amount'])) > 0) {
                $this->flag('warning', 'OVERFILL', "Row {$id} filled {$filled} > amount {$amount}", [$id]);
            }
            if ((string) $r['status'] === 'filled' && ! Money::isPositive($filled)) {
                $this->flag('warning', 'FILLED_WITHOUT_QTY', "Row {$id} is 'filled' but filled_amount is empty", [$id]);
            }
            if ((string) $r['status'] === 'filled' && empty($r['filled_at'])) {
                $this->flag('warning', 'FILLED_WITHOUT_TIME', "Row {$id} is 'filled' but filled_at is null", [$id]);
            }
            if (in_array((string) $r['status'], ['submission_unknown', 'pending'], true)) {
                $this->flag('warning', 'UNRESOLVED_SUBMISSION', "Row {$id} is still '{$r['status']}'", [$id]);
            }

            // Timing + acting path
            $timeToFill = ($r['filled_at'] ?? null) ? $this->secs($r['created_at'], $r['filled_at']) : null;
            $path = null;
            $latency = null;
            if ($this->hasExecution($r) && $r['filled_at']) {
                [$path, $latency] = $this->actingPath($wsActed[$id] ?? [], (string) $r['filled_at'], $haveLogs);
            }
            $exitDelay = null;
            $exitSizedAt = null;
            $sizing = null;
            if ($isExit && $parent !== null && ($parent['filled_at'] ?? null)) {
                $exitDelay = $this->secs($parent['filled_at'], $r['created_at']);
                $sz = $this->sizingLogFor($exitSized[(int) $parent['id']] ?? [], (string) $r['created_at']);
                if ($sz !== null) {
                    $exitSizedAt = $sz['ts'];
                    $sizing      = $sz['context'];
                }
            }

            $out[] = [
                'id' => $id, 'in_window' => $inWin, 'created_at' => $r['created_at'], 'nobitex_order_id' => $r['nobitex_order_id'],
                'client_order_id' => $r['client_order_id'], 'role' => $r['role'], 'side' => $side, 'price' => $price,
                'amount' => $amount, 'original_amount' => self::decOrNull($r['original_amount']), 'filled_amount' => $filled,
                'avg_fill_price' => $this->hasExecution($r) ? $avg : null, 'fee_amount' => self::decOrNull($r['fee_amount']),
                'fee_currency' => $r['fee_currency'], 'fee_source' => $r['fee_source'], 'effective_fee_bps' => $effBps,
                'status' => $r['status'], 'filled_at' => $r['filled_at'], 'paired_order_id' => $r['paired_order_id'] !== null ? (int) $r['paired_order_id'] : null,
                'exit_state' => $r['exit_state'] ?? null, 'exit_dust_delta' => self::decOrNull($r['exit_dust_delta'] ?? null),
                'time_to_fill_s' => $timeToFill, 'fill_path' => $path, 'w4_latency_ms' => $latency,
                'exit_delay_s' => $exitDelay, 'exit_sized_at' => $exitSizedAt, 'exit_sizing_log' => $sizing,
                'expected_exit_price' => $expectedExit, 'expected_exit_price_from_fill' => $expectedFromFill,
                'synced_by_sync_precision' => $isSynced, 'checks' => $checks,
            ];
        }

        $fills = array_values(array_filter($out, fn ($o) => $o['fill_path'] !== null));
        $paths = array_count_values(array_map(fn ($o) => (string) $o['fill_path'], $fills));
        $delays = array_values(array_filter(array_column($out, 'exit_delay_s'), fn ($v) => $v !== null));

        return [
            'rows'   => $out,
            'counts' => [
                'total'  => count($out),
                'by_status' => array_count_values(array_map(fn ($o) => (string) $o['status'], $out)),
                'by_role'   => array_count_values(array_map(fn ($o) => (string) ($o['role'] ?? 'null'), $out)),
                'fills'  => count($fills),
                'open'   => count(array_filter($out, fn ($o) => in_array($o['status'], self::OPEN_STATUSES, true))),
                'fill_paths' => $paths,
            ],
            'exit_delay_s' => self::stats($delays),
        ];
    }

    /** @return array{0:string, 1:?int} acting path label, W4 latency_ms */
    private function actingPath(array $acted, string $filledAt, bool $haveLogs): array
    {
        if (! $haveLogs) {
            return ['unknown (no logs)', null];
        }
        foreach ($acted as $e) {
            $c = $e['context'];
            if (! empty($c['pair_attempted']) || (($c['local_status'] ?? null) === 'filled' && abs($this->secs($filledAt, $e['ts'])) <= 3)) {
                return ['W4', isset($c['latency_ms']) && is_numeric($c['latency_ms']) ? (int) $c['latency_ms'] : null];
            }
        }
        return [$acted === [] ? 'poller' : 'poller (WS event late)', null];
    }

    /* ================================================================== */
    /* §3 Exchange reconciliation                                          */
    /* ================================================================== */

    private function exchangeRecon(array $rows, array $synced): array
    {
        if ($this->opt['no_exchange']) {
            return ['skipped' => true, 'reason' => '--no-exchange', 'rows' => [], 'mismatches' => [], 'wallet' => null];
        }
        $syncedSet = array_flip($synced);
        $result    = [];
        $mism      = [];
        $map       = ['filled' => 'FILLED', 'placed' => 'ACTIVE', 'partially_filled' => 'ACTIVE', 'cancelled' => 'CANCELED'];

        foreach ($rows as $r) {
            $nid = (string) ($r['nobitex_order_id'] ?? '');
            if ($nid === '' || str_starts_with($nid, 'SIM-')) {
                continue;
            }
            $id = (int) $r['id'];
            try {
                $this->pace();
                $dto = $this->nobitex->getOrdersStatus([$nid])[0] ?? null;
            } catch (\Throwable $e) {
                $dto = null;
            }
            if ($dto === null) {
                $result[] = ['id' => $id, 'nobitex_order_id' => $nid, 'available' => false];
                $this->flag('warning', 'EXCHANGE_STATUS_UNAVAILABLE', "Row {$id}: exchange status for {$nid} unavailable", [$id]);
                continue;
            }
            $ex = [
                'status' => $dto->status->value, 'amount' => self::dec($dto->amountBase), 'filled' => self::dec($dto->filledBase),
                'price' => $dto->priceIRT !== null ? (string) $dto->priceIRT : null, 'fee' => $dto->fee, 'average_price' => $dto->averagePrice,
            ];
            $local = [
                'status' => (string) $r['status'], 'amount' => self::dec($r['amount']), 'filled' => self::dec($r['filled_amount']),
                'price' => self::dec($r['price']), 'fee' => self::decOrNull($r['fee_amount']), 'average_price' => $this->hasExecution($r) ? $this->fillPrice($r) : null,
            ];
            $diffs = [];
            $want  = $map[$local['status']] ?? null;
            if ($want !== null && $want !== $ex['status']) {
                $diffs['status'] = [$local['status'], $ex['status']];
            }
            if (Money::compare($local['amount'], $ex['amount']) !== 0) {
                $diffs['amount'] = [$local['amount'], $ex['amount']];
            }
            if (Money::compare($local['filled'], $ex['filled']) !== 0) {
                $diffs['filled'] = [$local['filled'], $ex['filled']];
            }
            if ($ex['price'] !== null && Money::compare($local['price'], $ex['price']) !== 0) {
                $diffs['price'] = [$local['price'], $ex['price']];
            }
            if ($ex['fee'] !== null && Money::isPositive($ex['filled']) && Money::compare($local['fee'] ?? '0', self::dec($ex['fee'])) !== 0) {
                $diffs['fee'] = [$local['fee'], self::dec($ex['fee'])];
            }
            if ($ex['average_price'] !== null && Money::isPositive($ex['filled']) && $local['average_price'] !== null
                && Money::compare($local['average_price'], self::dec($ex['average_price'])) !== 0) {
                $diffs['average_price'] = [$local['average_price'], self::dec($ex['average_price'])];
            }

            $isSynced = isset($syncedSet[$id]);
            foreach ($diffs as $field => [$l, $x]) {
                $sev = match (true) {
                    $field === 'status' && ($ex['status'] === 'FILLED' || $local['status'] === 'filled') => 'critical',
                    $field === 'filled' => 'critical',
                    $isSynced && in_array($field, ['amount', 'price'], true) => 'info',
                    $field === 'amount' && $this->isTerminal($r) && Money::compare($x, Money::floorToScale($l, $this->qtyDec)) === 0 => 'info',
                    default => 'warning',
                };
                $note = $isSynced ? ' (row synced by grid:sync-precision — historic diff expected)' : '';
                if ($field === 'amount' && $sev === 'info' && ! $isSynced) {
                    $note = ' (exchange truncated an off-step amount; historic)';
                }
                $mism[] = ['id' => $id, 'nobitex_order_id' => $nid, 'field' => $field, 'db' => $l, 'exchange' => $x, 'severity' => $sev, 'note' => trim($note)];
                $this->flag($sev, 'EXCHANGE_' . strtoupper($field) . '_MISMATCH', "Row {$id} {$field}: DB {$l} vs exchange {$x}{$note}", [$id]);
            }
            $result[] = ['id' => $id, 'nobitex_order_id' => $nid, 'available' => true, 'db' => $local, 'exchange' => $ex, 'diff_fields' => array_keys($diffs)];
        }

        $wallet = null;
        try {
            $this->pace();
            $wallet = $this->nobitex->getBalances();
        } catch (\Throwable $e) {
            $this->flag('warning', 'WALLET_UNAVAILABLE', 'getBalances failed: ' . mb_substr($e->getMessage(), 0, 160));
        }

        return ['skipped' => false, 'rows' => $result, 'mismatches' => $mism, 'wallet' => $wallet,
            'checked' => count($result), 'unavailable' => count(array_filter($result, fn ($x) => ! $x['available']))];
    }

    private function pace(): void
    {
        if (! $this->firstCall) {
            Sleep::usleep($this->opt['rate_ms'] * 1000);
        }
        $this->firstCall = false;
        $this->httpCalls++;
    }

    /* ================================================================== */
    /* §4 Cycles                                                           */
    /* ================================================================== */

    private function cycles(string $from, string $to): array
    {
        $trades = CompletedTrade::where('bot_config_id', $this->bot->id)->orderBy('id')->get()
            ->map(fn ($t) => self::raw($t->getAttributes()))->all();
        $buyRate  = $this->fees->rateFraction($this->bot, 'buy');
        $sellRate = $this->fees->rateFraction($this->bot, 'sell');

        $out  = [];
        $seen = [];
        foreach ($trades as $t) {
            $tid = (int) $t['id'];
            $b   = $this->byId[(int) ($t['buy_order_id'] ?? 0)] ?? null;
            $s   = $this->byId[(int) ($t['sell_order_id'] ?? 0)] ?? null;
            if ($b === null || $s === null) {
                $this->flag('warning', 'CYCLE_LEG_MISSING', "completed_trade {$tid}: a leg row is missing", []);
                $out[] = ['id' => $tid, 'error' => 'leg missing'];
                continue;
            }
            $pairKey = $b['id'] . '/' . $s['id'];
            if (isset($seen[$pairKey])) {
                $this->flag('critical', 'CYCLE_DOUBLE_BOOKED', "Legs {$pairKey} booked twice (trades {$seen[$pairKey]} and {$tid})", [(int) $b['id'], (int) $s['id']]);
            }
            $seen[$pairKey] = $tid;
            $linked = (int) ($s['paired_order_id'] ?? 0) === (int) $b['id'] || (int) ($b['paired_order_id'] ?? 0) === (int) $s['id'];
            if (! $linked) {
                $this->flag('warning', 'CYCLE_LEGS_NOT_LINKED', "completed_trade {$tid}: legs {$pairKey} are not linked by paired_order_id", [(int) $b['id'], (int) $s['id']]);
            }

            $bq = $this->legQty($b);
            $sq = $this->legQty($s);
            $bp = $this->fillPrice($b);
            $sp = $this->fillPrice($s);
            [$bFee, $bCur, $bSrc] = $this->legFee($b, 'buy', $bq, $bp, $buyRate);
            [$sFee, $sCur, $sSrc] = $this->legFee($s, 'sell', $sq, $sp, $sellRate);

            $buyN   = Money::mul($bq, $bp);
            $sellN  = Money::mul($sq, $sp);
            $quoteFees = Money::add($bCur === 'quote' ? $bFee : '0', $sCur === 'quote' ? $sFee : '0');
            $baseFees  = Money::add($bCur === 'base' ? $bFee : '0', $sCur === 'base' ? $sFee : '0');
            $feeRial   = Money::add($quoteFees, Money::mul($baseFees, $bp)); // base fees valued at the buy price
            $residual  = Money::sub(Money::sub($bq, $sq), $baseFees);
            $cashGross = Money::sub($sellN, $buyN);
            $priceGross = Money::mul(Money::sub($sp, $bp), $sq);
            // net = rial cash flow − quote fees + BTC left behind valued at the buy price
            $net = Money::add(Money::sub($cashGross, $quoteFees), Money::mul($residual, $bp));

            $first = (string) ($b['filled_at'] ?? '') <= (string) ($s['filled_at'] ?? '') ? $b : $s;
            $last  = $first === $b ? $s : $b;
            $dur   = ($first['filled_at'] && $last['filled_at']) ? $this->secs($first['filled_at'], $last['filled_at']) : null;

            $storedNet = self::dec($t['net_profit'] ?? $t['profit'] ?? '0');
            $storedFee = self::dec($t['fee'] ?? '0');
            $storedRes = self::decOrNull($t['base_residual'] ?? null);
            $dNet = Money::sub($net, $storedNet);
            $dFee = Money::sub($feeRial, $storedFee);
            $dRes = $storedRes === null ? null : Money::sub($residual, $storedRes);
            $diff = [];
            if (Money::compare(Money::abs($dNet), '1') > 0) {
                $diff[] = "net Δ {$dNet}";
            }
            if (Money::compare(Money::abs($dFee), '1') > 0) {
                $diff[] = "fee Δ {$dFee}";
            }
            if ($dRes !== null && Money::compare(Money::abs($dRes), '0.0000000001') > 0) {
                $diff[] = "base_residual Δ {$dRes}";
            }
            if ($diff !== []) {
                $this->flag('warning', 'CYCLE_RECOMPUTE_DIFF', "completed_trade {$tid} ({$pairKey}): " . implode(', ', $diff), [(int) $b['id'], (int) $s['id']]);
            }
            if (Money::isNegative($net)) {
                $this->flag('critical', 'CYCLE_LOSS', "completed_trade {$tid} ({$pairKey}) recomputed net is negative: {$net}", [(int) $b['id'], (int) $s['id']]);
            }
            if (Money::compare(Money::abs($residual), $this->step()) >= 0) {
                $this->flag('info', 'CYCLE_RESIDUAL_GE_STEP', "completed_trade {$tid}: base residual {$residual} ≥ one qty step", [(int) $b['id'], (int) $s['id']]);
            }

            $out[] = [
                'id' => $tid, 'buy_id' => (int) $b['id'], 'sell_id' => (int) $s['id'], 'first_leg' => $first === $b ? 'buy' : 'sell',
                'buy_qty' => $bq, 'buy_px' => $bp, 'sell_qty' => $sq, 'sell_px' => $sp,
                'buy_notional' => $buyN, 'sell_notional' => $sellN,
                'buy_fee' => $bFee, 'buy_fee_currency' => $bCur, 'buy_fee_source' => $bSrc,
                'sell_fee' => $sFee, 'sell_fee_currency' => $sCur, 'sell_fee_source' => $sSrc,
                'gross_cash' => $cashGross, 'gross_price' => $priceGross, 'fee_rial' => $feeRial, 'net' => $net,
                'base_residual' => $residual, 'duration_s' => $dur, 'closed_at' => $last['filled_at'],
                'stored_net' => $storedNet, 'stored_fee' => $storedFee, 'stored_base_residual' => $storedRes,
                'diff' => $diff, 'linked' => $linked,
                'net_per_1m_deployed' => Money::isPositive($buyN) ? Money::div(Money::mul($net, '1000000'), $buyN, 4) : null,
            ];
        }

        // Exit fills that closed a cycle but have no booking.
        $booked = [];
        foreach ($out as $c) {
            if (isset($c['buy_id'])) {
                $booked[$c['buy_id']] = $booked[$c['sell_id']] = true;
            }
        }
        $missing = [];
        foreach ($this->byId as $r) {
            if ($this->isExit($r) && (string) $r['status'] === 'filled') {
                $p = $this->byId[(int) $r['paired_order_id']] ?? null;
                if ($p !== null && $this->hasExecution($p) && ! isset($booked[(int) $r['id']])) {
                    $missing[] = (int) $r['id'];
                    $this->flag('critical', 'CYCLE_NOT_BOOKED', "Exit {$r['id']} filled (parent {$p['id']}) but no completed_trade books the cycle", [(int) $r['id'], (int) $p['id']]);
                }
            }
        }

        $ok    = array_values(array_filter($out, fn ($c) => isset($c['net'])));
        $nets  = array_column($ok, 'net');
        $durs  = array_values(array_filter(array_column($ok, 'duration_s'), fn ($v) => $v !== null));
        $per1m = array_values(array_filter(array_column($ok, 'net_per_1m_deployed'), fn ($v) => $v !== null));
        $sum   = fn (array $xs) => array_reduce($xs, fn ($c, $x) => Money::add($c, (string) $x), '0');

        return [
            'rows' => $out,
            'not_booked_exit_ids' => $missing,
            'totals' => [
                'count' => count($ok),
                'net' => $sum($nets), 'stored_net' => $sum(array_column($ok, 'stored_net')),
                'fees' => $sum(array_column($ok, 'fee_rial')), 'gross_cash' => $sum(array_column($ok, 'gross_cash')),
                'gross_price' => $sum(array_column($ok, 'gross_price')), 'buy_notional' => $sum(array_column($ok, 'buy_notional')),
                'base_residual' => $sum(array_column($ok, 'base_residual')),
                'duration_s' => self::stats($durs),
                'avg_net' => $ok === [] ? '0' : Money::div($sum($nets), (string) count($ok), 2),
                'median_net' => self::median($nets),
                'avg_net_per_cycle_per_1m_deployed' => $per1m === [] ? null : Money::div($sum($per1m), (string) count($per1m), 2),
                'by_first_leg' => array_count_values(array_column($ok, 'first_leg')),
            ],
        ];
    }

    /** @return array{0:string,1:string,2:string} fee, currency, source */
    private function legFee(array $r, string $side, string $qty, string $px, string $rate): array
    {
        if ($r['fee_amount'] !== null && in_array($r['fee_currency'], ['base', 'quote'], true)) {
            return [self::dec($r['fee_amount']), (string) $r['fee_currency'], (string) ($r['fee_source'] ?? 'estimated')];
        }
        $cur = $this->fees->expectedCurrency($side);
        $fee = $cur === 'base' ? Money::mul($qty, $rate) : Money::mul(Money::mul($qty, $px), $rate);
        return [$fee, $cur, 'estimated(audit)'];
    }

    /* ================================================================== */
    /* §5 Base-dust ledger replay                                          */
    /* ================================================================== */

    private function dustReplay(array $rows): array
    {
        $sized = $this->eventsBy('EXIT_SIZED', 'filled_order_id');
        $steps = [];

        foreach ($rows as $r) {
            $id = (int) $r['id'];
            // (1) exit SELL sized from a buy fill: delta = credited − exit amount.
            if ($this->isExit($r) && (string) $r['type'] === 'sell') {
                $p = $this->byId[(int) $r['paired_order_id']] ?? null;
                if ($p === null) {
                    continue;
                }
                $reverted = (string) $r['status'] === 'cancelled' && $r['exit_dust_delta'] !== null
                    && Money::isZero(self::dec($r['exit_dust_delta'])) && (int) ($p['paired_order_id'] ?? 0) !== $id;
                if ($reverted) {
                    $steps[] = ['at' => $r['created_at'], 'row' => $id, 'kind' => 'exit_sell_reverted', 'delta' => '0', 'stored' => '0', 'source' => 'row'];
                    continue;
                }
                $pq = $this->legQty($p);
                $baseFee = ($p['fee_amount'] !== null && $p['fee_currency'] !== null)
                    ? ($p['fee_currency'] === 'base' ? self::dec($p['fee_amount']) : '0')
                    : $this->fees->estimate('buy', $pq, $this->fillPrice($p), $this->bot)['amount'];
                $credited = Money::sub($pq, $baseFee);
                $log      = $this->sizingLogFor($sized[(int) $p['id']] ?? [], (string) $r['created_at'])['context'] ?? null;
                $exitAmt  = $log !== null && isset($log['amount']) ? self::dec($log['amount']) : self::dec($r['original_amount'] ?? $r['amount']);
                $delta    = Money::sub($credited, $exitAmt);
                $steps[]  = [
                    'at' => $r['created_at'], 'row' => $id, 'kind' => 'exit_sell_sized', 'parent' => (int) $p['id'],
                    'credited' => $credited, 'exit_amount' => $exitAmt, 'exit_amount_source' => $log !== null ? 'EXIT_SIZED log' : 'row',
                    'delta' => $delta, 'stored' => self::decOrNull($r['exit_dust_delta']),
                    'log_dust_before' => $log['dust_before'] ?? null, 'log_dust_after' => $log['dust_after'] ?? null,
                ];
            }
            // (2) exit BUY filled: delta = (credited by the buy) − (BTC the parent sell removed).
            if ($this->isExit($r) && (string) $r['type'] === 'buy' && (string) $r['status'] === 'filled') {
                $p = $this->byId[(int) $r['paired_order_id']] ?? null;
                if ($p === null || (string) $p['type'] !== 'sell') {
                    continue;
                }
                if ($r['net_base_delta'] === null || $p['net_base_delta'] === null) {
                    $steps[] = ['at' => $r['filled_at'], 'row' => $id, 'kind' => 'exit_buy_not_settled', 'delta' => '0', 'stored' => self::decOrNull($r['exit_dust_delta']), 'note' => 'pre-fee-model row: settleExitBuyFill skips it'];
                    continue;
                }
                $bq = $this->legQty($r);
                $bFee = $r['fee_currency'] === 'base' ? self::dec($r['fee_amount']) : '0';
                $sq = $this->legQty($p);
                $sFee = $p['fee_currency'] === 'base' ? self::dec($p['fee_amount']) : '0';
                $delta = Money::sub(Money::sub($bq, $bFee), Money::add($sq, $sFee));
                $steps[] = ['at' => $r['filled_at'], 'row' => $id, 'kind' => 'exit_buy_settled', 'parent' => (int) $p['id'], 'delta' => $delta, 'stored' => self::decOrNull($r['exit_dust_delta'])];
            }
            // (3) partial of a cancelled order absorbed into dust.
            if ((string) ($r['exit_state'] ?? '') === 'dusted') {
                $q = $this->legQty($r);
                $fee = $r['fee_currency'] === 'base' ? self::dec($r['fee_amount']) : '0';
                $delta = (string) $r['type'] === 'buy' ? Money::sub($q, $fee) : Money::sub('0', Money::add($q, $fee));
                $steps[] = ['at' => $r['exit_blocked_at'] ?? $r['updated_at'], 'row' => $id, 'kind' => 'partial_dusted', 'delta' => $delta, 'stored' => self::decOrNull($r['exit_dust_delta'])];
            }
        }
        usort($steps, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']) ?: $a['row'] <=> $b['row']);

        $dust = '0';
        $storedSum = '0';
        $breaks = [];
        foreach ($steps as &$s) {
            $s['dust_before'] = $dust;
            $dust = Money::add($dust, $s['delta']);
            $s['dust_after'] = $dust;
            $storedSum = Money::add($storedSum, $s['stored'] ?? '0');
            if ($s['stored'] !== null && Money::compare(Money::abs(Money::sub($s['stored'], $s['delta'])), '0.0000000001') > 0) {
                $this->flag('warning', 'DUST_DELTA_MISMATCH', "Row {$s['row']} exit_dust_delta {$s['stored']} ≠ recomputed {$s['delta']}", [$s['row']]);
            }
            // What the bot saw (EXIT_SIZED dust_before/after) must equal the replay at that point.
            if (($s['log_dust_before'] ?? null) !== null
                && (Money::compare(self::dec($s['log_dust_before']), $s['dust_before']) !== 0
                    || Money::compare(self::dec($s['log_dust_after']), $s['dust_after']) !== 0)) {
                $breaks[] = $s['row'];
            }
        }
        unset($s);

        $botDust = self::dec($this->bot->getAttributes()['base_dust'] ?? '0');
        $diff = Money::sub($dust, $botDust);
        if (Money::compare(Money::abs($diff), '0.0000000001') > 0) {
            $this->flag('warning', 'DUST_LEDGER_MISMATCH', "Replayed base_dust {$dust} ≠ bot_configs.base_dust {$botDust} (Δ {$diff})", []);
        }
        if ($breaks !== []) {
            $this->flag('warning', 'DUST_LOG_DIVERGES', 'EXIT_SIZED dust_before/after logged by the bot differ from the replay at rows ' . implode(',', $breaks) . ' (a dust move the replay does not know about: a revert, a manual base_dust edit, or a missing row)', $breaks);
        }

        return [
            'steps' => $steps, 'replayed' => $dust, 'stored_sum_exit_dust_delta' => $storedSum,
            'bot_config_base_dust' => $botDust, 'diff' => $diff, 'log_divergences' => $breaks,
            'step_size' => $this->step(),
        ];
    }

    /* ================================================================== */
    /* §6 Balance reconciliation                                           */
    /* ================================================================== */

    private function balance(array $rows, string $from, ?array $wallet): array
    {
        $dRls = '0';
        $dBtc = '0';
        $flows = 0;
        $estimated = 0;
        foreach ($rows as $r) {
            if (! $this->hasExecution($r)) {
                continue;
            }
            $at = (string) ($r['filled_at'] ?? $r['last_fill_at'] ?? $r['updated_at'] ?? '');
            if ($at < $from) {
                continue;
            }
            $q  = $this->legQty($r);
            $px = $this->fillPrice($r);
            $rate = $this->fees->rateFraction($this->bot, (string) $r['type']);
            [$fee, $cur] = $this->legFee($r, (string) $r['type'], $q, $px, $rate);
            if ($r['fee_amount'] === null) {
                $estimated++;
            }
            $notional = Money::mul($q, $px);
            if ((string) $r['type'] === 'buy') {
                $dRls = Money::sub($dRls, $notional);
                $dBtc = Money::add($dBtc, $q);
            } else {
                $dRls = Money::add($dRls, $notional);
                $dBtc = Money::sub($dBtc, $q);
            }
            $cur === 'base' ? $dBtc = Money::sub($dBtc, $fee) : $dRls = Money::sub($dRls, $fee);
            $flows++;
        }

        // Expected locks of open orders: buy locks rial (price × remaining), sell locks BTC.
        $lock = function (array $set): array {
            $rls = '0';
            $btc = '0';
            foreach ($set as $r) {
                $rem = Money::sub(self::dec($r['amount']), self::dec($r['filled_amount']));
                if (! Money::isPositive($rem)) {
                    continue;
                }
                (string) $r['type'] === 'buy' ? $rls = Money::add($rls, Money::mul($rem, self::dec($r['price']))) : $btc = Money::add($btc, $rem);
            }
            return ['rls' => $rls, 'btc' => $btc];
        };
        $openBot = array_filter($rows, fn ($r) => in_array((string) $r['status'], ['placed', 'partially_filled'], true));
        $openAll = [];
        try {
            $openAll = GridOrder::whereIn('status', ['placed', 'partially_filled'])
                ->whereNotNull('nobitex_order_id')->where('nobitex_order_id', 'not like', 'SIM-%')
                ->get()->map(fn ($o) => self::raw($o->getAttributes()))->all();
        } catch (\Throwable) {
        }

        $out = [
            'method' => 'Σ over every fill (filled_amount > 0) with fill time ≥ window start: buy → rls −= qty×avg, btc += qty; sell → rls += qty×avg, btc −= qty; then the fee leaves in its own currency (stored fee, else an estimate at the configured rate).',
            'fills_counted' => $flows, 'fills_with_estimated_fee' => $estimated,
            'delta' => ['rls' => $dRls, 'btc' => $dBtc],
            'expected_locked_this_bot' => $lock($openBot),
            'expected_locked_all_bots' => $lock($openAll),
            'start' => null, 'expected_now' => null, 'wallet' => null, 'residual' => null, 'start_nature' => null,
        ];

        $startRls = $this->opt['start_rls'] ?? null;
        $startBtc = $this->opt['start_btc'] ?? null;
        if ($startRls !== null && $startBtc !== null) {
            $out['start'] = ['rls' => $startRls, 'btc' => $startBtc, 'at' => $from];
            $out['expected_now'] = ['rls' => Money::add($startRls, $dRls), 'btc' => Money::add($startBtc, $dBtc)];
        }

        if (is_array($wallet)) {
            $w = fn (string $c, string $k) => self::dec($wallet[$c][$k] ?? '0');
            $out['wallet'] = [
                'rls' => ['total' => $w('rls', 'total'), 'available' => $w('rls', 'available'), 'locked' => $w('rls', 'locked')],
                'btc' => ['total' => $w('btc', 'total'), 'available' => $w('btc', 'available'), 'locked' => $w('btc', 'locked')],
            ];
            $lockAll = $out['expected_locked_all_bots'];
            $out['locked_vs_open_orders'] = [
                'rls' => Money::sub($out['wallet']['rls']['locked'], $lockAll['rls']),
                'btc' => Money::sub($out['wallet']['btc']['locked'], $lockAll['btc']),
            ];
            foreach (['rls' => '1', 'btc' => '0.00000001'] as $c => $tol) {
                if (Money::compare(Money::abs($out['locked_vs_open_orders'][$c]), $tol) > 0) {
                    $this->flag('warning', 'LOCKED_BALANCE_MISMATCH', "Wallet locked {$c} {$out['wallet'][$c]['locked']} vs Σ open grid orders (all bots) {$lockAll[$c]} — Δ {$out['locked_vs_open_orders'][$c]} (manual orders? other software?)", []);
                }
            }
            if ($out['expected_now'] !== null) {
                $out['residual'] = [
                    'rls' => Money::sub($out['wallet']['rls']['total'], $out['expected_now']['rls']),
                    'btc' => Money::sub($out['wallet']['btc']['total'], $out['expected_now']['btc']),
                ];
                $out['residual_explanations'] = [
                    'other bots trading on the same account (their fills since the snapshot are not in this ledger)',
                    'manual trades / deposits / withdrawals since the snapshot',
                    'start snapshot taken as activeBalance (excludes funds blocked at that moment) instead of total',
                    'fees estimated where the exchange fee was not captured',
                ];
                $out['start_nature'] = $this->startNature($out);
                foreach (['rls' => '1000', 'btc' => '0.000001'] as $c => $tol) {
                    if (Money::compare(Money::abs($out['residual'][$c]), $tol) > 0) {
                        $this->flag('warning', 'BALANCE_RESIDUAL', "Wallet {$c} total {$out['wallet'][$c]['total']} vs start + Σ fill cash flows {$out['expected_now'][$c]}: residual {$out['residual'][$c]}", []);
                    }
                }
            }
        }

        return $out;
    }

    /** ESTIMATE: was the start snapshot a total or an activeBalance? */
    private function startNature(array $b): array
    {
        $r = $b['residual'];
        $otherBots = Money::sub($b['expected_locked_all_bots']['rls'], $b['expected_locked_this_bot']['rls']);
        if (Money::compare(Money::abs($r['rls']), '1000') <= 0 && Money::compare(Money::abs($r['btc']), '0.000001') <= 0) {
            $verdict = 'consistent with TOTAL (or activeBalance with nothing blocked at the snapshot): the ledger explains the wallet to within 1000 rial / 1e-6 BTC.';
        } elseif (Money::isPositive($r['rls']) || Money::isPositive($r['btc'])) {
            $verdict = 'wallet holds MORE than start + flows — consistent with the start being activeBalance (funds blocked at the snapshot were not counted), or with deposits / other bots\' profits.';
        } else {
            $verdict = 'wallet holds LESS than start + flows — not explained by an activeBalance snapshot; look for withdrawals, manual trades or other bots.';
        }
        return ['label' => 'ESTIMATE', 'verdict' => $verdict, 'other_bots_locked_rls_now' => $otherBots,
            'note' => 'Not determinable from data alone: the snapshot\'s blocked balance at that moment was not recorded.'];
    }

    /* ================================================================== */
    /* §7 Open position & unrealized P&L                                   */
    /* ================================================================== */

    private function position(array $rows, array $cycles, array $balance, array $market): array
    {
        [$pxNow, $pxSrc] = $this->priceNow($rows, $market);
        $sellRate = $this->fees->rateFraction($this->bot, 'sell');
        $buyRate  = $this->fees->rateFraction($this->bot, 'buy');

        $longs = [];
        $shorts = [];
        $inventory = ['btc' => '0', 'rows' => []];
        foreach ($rows as $r) {
            if (! in_array((string) $r['status'], self::OPEN_STATUSES, true)) {
                continue;
            }
            $rem = Money::sub(self::dec($r['amount']), self::dec($r['filled_amount']));
            if ($this->isExit($r)) {
                $p = $this->byId[(int) $r['paired_order_id']] ?? null;
                if ($p === null) {
                    continue;
                }
                $pq = $this->legQty($p);
                $pp = $this->fillPrice($p);
                if ((string) $r['type'] === 'sell') {
                    $fee = $p['fee_currency'] === 'base' ? self::dec($p['fee_amount']) : Money::mul($pq, $buyRate);
                    $credited = Money::sub($pq, $fee);
                    $cost = Money::isPositive($credited) ? Money::div(Money::mul(Money::mul($pq, $pp), $rem), $credited) : '0';
                    $row = ['exit_id' => (int) $r['id'], 'parent_id' => (int) $p['id'], 'btc_held' => $rem, 'buy_px' => $pp, 'cost_basis' => $cost, 'exit_px' => self::dec($r['price'])];
                    if ($pxNow !== null) {
                        $value = Money::mul($rem, $pxNow);
                        $row['value_now'] = $value;
                        $row['unrealized_after_est_exit_fee'] = Money::sub(Money::sub($value, $cost), Money::mul($value, $sellRate));
                    }
                    $longs[] = $row;
                } else {
                    $sFee = $p['fee_currency'] === 'quote' ? self::dec($p['fee_amount']) : Money::mul(Money::mul($pq, $pp), $sellRate);
                    $proceeds = Money::sub(Money::mul($pq, $pp), $sFee);
                    $row = ['exit_id' => (int) $r['id'], 'parent_id' => (int) $p['id'], 'btc_owed' => $pq, 'sell_px' => $pp, 'proceeds' => $proceeds, 'exit_px' => self::dec($r['price'])];
                    if ($pxNow !== null) {
                        $buyBack = Money::mul(Money::div($pq, Money::sub('1', $buyRate)), $pxNow);
                        $row['buy_back_cost_now'] = $buyBack;
                        $row['unrealized'] = Money::sub($proceeds, $buyBack);
                    }
                    $shorts[] = $row;
                }
            } elseif ((string) $r['type'] === 'sell') {
                $inventory['btc'] = Money::add($inventory['btc'], $rem);
                $inventory['rows'][] = (int) $r['id'];
            }
        }

        $sum = fn (array $xs, string $k) => array_reduce($xs, fn ($c, $x) => Money::add($c, (string) ($x[$k] ?? '0')), '0');
        $unreal = $pxNow === null ? null : Money::add($sum($longs, 'unrealized_after_est_exit_fee'), $sum($shorts, 'unrealized'));
        $realized = $cycles['totals']['net'];

        $out = [
            'price_now' => $pxNow, 'price_now_source' => $pxSrc,
            'open_long_cycles' => $longs, 'open_short_cycles' => $shorts,
            'btc_held_open_cycles' => $sum($longs, 'btc_held'), 'cost_basis_open_cycles' => $sum($longs, 'cost_basis'),
            'btc_owed_open_short_cycles' => $sum($shorts, 'btc_owed'),
            'grid_sell_inventory_on_book' => $inventory,
            'realized_net' => $realized, 'unrealized' => $unreal,
            'net_pnl' => $unreal === null ? null : Money::add($realized, $unreal),
            'benchmark' => null,
        ];

        $p0 = $market['stats']['first_open'] ?? null;
        if ($pxNow !== null && $p0 !== null) {
            $bm = ['label' => 'ESTIMATE (candle prices)', 'price_start' => $p0, 'price_now' => $pxNow];
            $active = $this->activeBudget();
            $bm['active_budget_all_btc_pnl'] = Money::mul($active, Money::sub(Money::div($pxNow, $p0), '1'));
            if ($balance['start'] !== null) {
                $s = $balance['start'];
                $bm['start_equity'] = Money::add($s['rls'], Money::mul($s['btc'], $p0));
                $bm['hold_equity_now'] = Money::add($s['rls'], Money::mul($s['btc'], $pxNow));
                $e = $balance['expected_now'];
                $bm['bot_ledger_equity_now'] = Money::add($e['rls'], Money::mul($e['btc'], $pxNow));
                $bm['bot_minus_hold_ledger'] = Money::sub($bm['bot_ledger_equity_now'], $bm['hold_equity_now']);
                if ($balance['wallet'] !== null) {
                    $w = $balance['wallet'];
                    $bm['wallet_equity_now'] = Money::add($w['rls']['total'], Money::mul($w['btc']['total'], $pxNow));
                    $bm['wallet_minus_hold'] = Money::sub($bm['wallet_equity_now'], $bm['hold_equity_now']);
                }
            }
            $out['benchmark'] = $bm;
        }

        return $out;
    }

    /** @return array{0:?string,1:string} */
    private function priceNow(array $rows, array $market): array
    {
        if (($market['stats']['last_close'] ?? null) !== null) {
            return [$market['stats']['last_close'], 'last candle close (' . ($market['resolution'] ?? '?') . ')'];
        }
        try {
            $c = Cache::get('btc_price');
            if (is_numeric($c) && $this->symbol === 'BTCIRT') {
                return [self::dec($c), "cache 'btc_price' (read-only; age unknown)"];
            }
        } catch (\Throwable) {
        }
        $last = null;
        foreach ($rows as $r) {
            if ($this->hasExecution($r) && ($last === null || (string) $r['filled_at'] > (string) $last['filled_at'])) {
                $last = $r;
            }
        }
        return $last !== null ? [$this->fillPrice($last), "last fill price (row {$last['id']}, {$last['filled_at']}) — stale proxy"] : [null, 'unavailable'];
    }

    /* ================================================================== */
    /* §8 Infrastructure health                                            */
    /* ================================================================== */

    private function infra(): array
    {
        $cats = [
            'ERROR'                   => fn ($e) => $e['level'] === 'ERROR',
            'CRITICAL'                => fn ($e) => in_array($e['level'], ['CRITICAL', 'ALERT', 'EMERGENCY'], true),
            'WARNING'                 => fn ($e) => $e['level'] === 'WARNING',
            'WS_PRIVATE_RECONNECT'    => fn ($e) => str_starts_with($e['message'], '[WS-PRIVATE] Connection dropped'),
            'WS_PRIVATE_FLAPPING'     => fn ($e) => $e['event'] === 'WS_PRIVATE_FLAPPING',
            'WS_PRIVATE_CONNECT_FAILED' => fn ($e) => $e['event'] === 'WS_PRIVATE_CONNECT_FAILED',
            'WS_FEED_*'               => fn ($e) => str_starts_with($e['event'], 'WS_FEED_'),
            'WS_EVENT_API_LAG_*'      => fn ($e) => str_starts_with($e['event'], 'WS_EVENT_API_LAG'),
            'WS_EVENT_JOB_FAILED'     => fn ($e) => $e['event'] === 'WS_EVENT_JOB_FAILED',
            'ORDER_PRICE_ROUNDED'     => fn ($e) => $e['event'] === 'ORDER_PRICE_ROUNDED',
            'PRECISION_*'             => fn ($e) => str_starts_with($e['event'], 'PRECISION_') && $e['event'] !== 'PRECISION_ROW_SYNCED',
            'FEE_RATE_DRIFT'          => fn ($e) => $e['event'] === 'FEE_RATE_DRIFT',
            'FEE_CURRENCY_UNEXPECTED' => fn ($e) => $e['event'] === 'FEE_CURRENCY_UNEXPECTED',
            'EXIT_BLOCKED'            => fn ($e) => $e['event'] === 'EXIT_BLOCKED',
            'EXIT_REJECTED'           => fn ($e) => $e['event'] === 'EXIT_REJECTED',
            'QUEUE_DEPTH_*'           => fn ($e) => str_starts_with($e['event'], 'QUEUE_DEPTH_'),
            'KILL_SWITCH_*'           => fn ($e) => str_starts_with($e['event'], 'KILL_SWITCH_'),
            'RECONCILE_STUCK/UNRESOLVED' => fn ($e) => in_array($e['event'], ['RECONCILE_STUCK', 'RECONCILE_UNRESOLVED'], true),
        ];
        $totals = array_fill_keys(array_keys($cats), 0);
        $perDay = [];
        $topErr = [];
        foreach ($this->logs as $e) {
            if ($e['ts'] < $this->from) {
                continue;
            }
            $day = substr($e['ts'], 0, 10);
            foreach ($cats as $name => $fn) {
                if ($fn($e)) {
                    $totals[$name]++;
                    $perDay[$day][$name] = ($perDay[$day][$name] ?? 0) + 1;
                }
            }
            if (in_array($e['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                $k = $e['level'] . ' ' . $e['event'];
                $topErr[$k] = ($topErr[$k] ?? 0) + 1;
            }
        }
        ksort($perDay);
        arsort($topErr);

        $lat = [];
        $outcomes = [];
        foreach ($this->botEvents('WS_EVENT_ACTED') as $e) {
            if (isset($e['context']['latency_ms']) && is_numeric($e['context']['latency_ms'])) {
                $lat[] = (int) $e['context']['latency_ms'];
            }
            $o = (string) ($e['context']['outcome'] ?? '?');
            $outcomes[$o] = ($outcomes[$o] ?? 0) + 1;
        }

        foreach (['WS_PRIVATE_FLAPPING' => 'warning', 'QUEUE_DEPTH_*' => 'warning', 'FEE_CURRENCY_UNEXPECTED' => 'warning', 'EXIT_BLOCKED' => 'critical', 'WS_EVENT_JOB_FAILED' => 'warning', 'CRITICAL' => 'warning'] as $k => $sev) {
            if ($totals[$k] > 0) {
                $this->flag($sev, 'LOG_' . preg_replace('/[^A-Z_]/', '', $k), "{$totals[$k]} {$k} log line(s) in the window", []);
            }
        }
        if ($this->logs === []) {
            $this->flag('warning', 'NO_LOGS', 'No log lines found in the window under ' . $this->opt['log_dir'] . ' — §2 paths, §5 log chain and §8 are blind', []);
        }

        return [
            'totals' => $totals, 'per_day' => $perDay, 'top_errors' => array_slice($topErr, 0, 15, true),
            'w4_latency_ms' => self::stats($lat), 'w4_outcomes' => $outcomes,
            'note' => 'Counts are over ALL log lines in the window (every bot and process), testing.* lines ignored; W4 latency is over this bot\'s WS_EVENT_ACTED lines only.',
        ];
    }

    /* ================================================================== */
    /* events.csv timeline                                                 */
    /* ================================================================== */

    /** @return list<array{0:string,1:string,2:string,3:string,4:string,5:string}> ts, source, level, event, row_ids, detail */
    private function events(array $rows, string $from, string $to): array
    {
        $ev = [];
        foreach ($rows as $r) {
            if ((string) $r['created_at'] >= $from && (string) $r['created_at'] <= $to) {
                $ev[] = [(string) $r['created_at'], 'db:grid_orders', 'INFO', 'ORDER_CREATED', (string) $r['id'],
                    "{$r['role']} {$r['type']} " . self::dec($r['amount']) . ' @ ' . self::dec($r['price']) . ($r['paired_order_id'] ? " parent/pair {$r['paired_order_id']}" : '')];
            }
            if ($r['filled_at'] && (string) $r['filled_at'] >= $from && (string) $r['filled_at'] <= $to) {
                $ev[] = [(string) $r['filled_at'], 'db:grid_orders', 'INFO', 'ORDER_FILLED', (string) $r['id'],
                    "{$r['type']} " . self::dec($r['filled_amount']) . ' @ ' . $this->fillPrice($r) . " fee {$r['fee_amount']} {$r['fee_currency']} ({$r['fee_source']})"];
            }
        }
        foreach (CompletedTrade::where('bot_config_id', $this->bot->id)->whereBetween('created_at', [$from, $to])->get() as $t) {
            $a = self::raw($t->getAttributes());
            $ev[] = [(string) $a['created_at'], 'db:completed_trades', 'INFO', 'CYCLE_BOOKED', "{$a['buy_order_id']},{$a['sell_order_id']}", 'trade ' . $a['id'] . ' net ' . self::dec($a['net_profit'] ?? $a['profit'])];
        }
        $infra = '/^(WS_PRIVATE_|WS_FEED_|QUEUE_DEPTH_|WS_EVENT_API_LAG|\[WS-PRIVATE\] Connection dropped|\[WS-PRIVATE\] Connected|ORDER_PRICE_ROUNDED|PRECISION_FALLBACK|PRECISION_DRIFT)/';
        $bot = array_flip($this->botLogs);
        foreach ($this->logs as $i => $e) {
            if (! isset($bot[$i]) && ! preg_match($infra, $e['event'])) {
                continue;
            }
            $c = $e['context'];
            $ids = [];
            foreach (['grid_order_id', 'filled_order_id', 'exit_order_id', 'order_id', 'buy_order_id', 'sell_order_id'] as $k) {
                if (isset($c[$k]) && is_scalar($c[$k]) && isset($this->byId[(int) $c[$k]])) {
                    $ids[] = (string) $c[$k];
                }
            }
            $ev[] = [$e['ts'], 'log:' . $e['file'], $e['level'], $e['event'], implode(',', array_unique($ids)),
                mb_substr(json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 0, 500)];
        }
        try {
            $routine = ['CHECK_TRADES_START', 'CHECK_TRADES_END', 'API_CALL', 'PRICE_CHECK', 'ORDERS_RECEIVED', 'WAITING_FOR', 'CHECK_START', 'CHECK_END'];
            foreach (BotActivityLog::where('bot_config_id', $this->bot->id)->whereBetween('created_at', [$from, $to])
                ->whereNotIn('action_type', $routine)->orderBy('id')->limit(5000)->get() as $a) {
                $at = self::raw($a->getAttributes());
                $ev[] = [(string) $at['created_at'], 'db:bot_activity_logs', (string) $at['level'], (string) $at['action_type'], '', mb_substr((string) $at['message'], 0, 300)];
            }
        } catch (\Throwable) {
        }
        try {
            if (Schema::hasTable('exchange_ws_events')) {
                foreach (DB::table('exchange_ws_events')->whereIn('matched_grid_order_id', array_keys($this->byId))
                    ->whereBetween('received_at', [$from, $to])->orderBy('id')->get() as $w) {
                    $ev[] = [(string) $w->received_at, 'db:exchange_ws_events', 'INFO', 'WS_' . strtoupper((string) $w->channel), (string) $w->matched_grid_order_id,
                        "status {$w->status} event_time_ms {$w->event_time_ms} local_at_receipt {$w->local_status_at_receipt}"];
                }
            }
        } catch (\Throwable) {
        }
        usort($ev, fn ($a, $b) => strcmp($a[0], $b[0]));
        return $ev;
    }

    /* ================================================================== */
    /* §9 Market context & missed opportunity (ESTIMATE)                   */
    /* ================================================================== */

    private function market(array $rows, string $from, string $to, array $builds, array $cycles): array
    {
        $res = strtolower((string) $this->opt['candles']);
        if (! isset(self::RESOLUTIONS[$res])) {
            $this->flag('info', 'CANDLES_BAD_RESOLUTION', "--candles={$res} is not one of " . implode(',', array_keys(self::RESOLUTIONS)), []);
            return ['skipped' => true, 'reason' => "unsupported resolution {$res}", 'stats' => []];
        }
        if ($this->opt['no_exchange']) {
            return ['skipped' => true, 'reason' => '--no-exchange (candles are an HTTP read)', 'resolution' => $res, 'stats' => []];
        }
        [$udf, $secs] = self::RESOLUTIONS[$res];
        $tz    = (string) config('app.timezone');
        $fromT = Carbon::parse($from, $tz)->getTimestamp();
        $toT   = Carbon::parse($to, $tz)->getTimestamp();

        $candles = [];
        $errors  = [];
        $chunk   = 499 * $secs;
        for ($t0 = $fromT; $t0 < $toT && count($errors) < 3; $t0 += $chunk) {
            try {
                $this->pace();
                $r = $this->nobitex->getOhlc($this->symbol, $udf, min($toT, $t0 + $chunk), $t0);
                if (($r['status'] ?? '') === 'error') {
                    $errors[] = (string) ($r['errmsg'] ?? 'error');
                }
                foreach ((array) ($r['candles'] ?? []) as $c) {
                    $candles[(int) $c['t']] = $c;
                }
            } catch (\Throwable $e) {
                $errors[] = mb_substr($e->getMessage(), 0, 160);
            }
        }
        ksort($candles);
        $irt = CandleService::priceUnit($this->symbol) === 'IRR';
        $cs = [];
        foreach ($candles as $t => $c) {
            if ($t < $fromT - $secs || $t > $toT) {
                continue;
            }
            $row = ['t' => (int) $t];
            foreach (['o', 'h', 'l', 'c'] as $f) {
                $v = self::dec($c[$f] ?? '0');
                $row[$f] = $irt ? Money::mul($v, '10') : $v; // UDF IRT candles are TOMAN
            }
            $cs[] = $row;
        }
        if ($cs === []) {
            $this->flag('info', 'NO_CANDLES', 'No candles loaded for the window' . ($errors ? ': ' . implode('; ', $errors) : ''), []);
            return ['skipped' => true, 'reason' => 'no candles', 'resolution' => $res, 'errors' => $errors, 'stats' => []];
        }

        // Stats
        $hi = $cs[0]['h'];
        $lo = $cs[0]['l'];
        $rets = [];
        foreach ($cs as $i => $c) {
            $hi = Money::max($hi, $c['h']);
            $lo = Money::min($lo, $c['l']);
            if ($i > 0 && Money::isPositive($cs[$i - 1]['c'])) {
                $rets[] = Money::sub(Money::div($c['c'], $cs[$i - 1]['c']), '1');
            }
        }
        $n = count($rets);
        $mean = $n ? Money::div(array_reduce($rets, fn ($a, $x) => Money::add($a, $x), '0'), (string) $n) : '0';
        $var  = $n > 1 ? Money::div(array_reduce($rets, fn ($a, $x) => Money::add($a, Money::mul(Money::sub($x, $mean), Money::sub($x, $mean))), '0'), (string) ($n - 1)) : '0';
        $sd   = bcsqrt($var, 12);
        $first = $cs[0]['o'];
        $last  = $cs[count($cs) - 1]['c'];
        $stats = [
            'candles' => count($cs), 'first_open' => $first, 'last_close' => $last, 'high' => $hi, 'low' => $lo,
            'range_pct' => Money::div(Money::mul(Money::sub($hi, $lo), '100'), $lo, 3),
            'change_pct' => Money::div(Money::mul(Money::sub($last, $first), '100'), $first, 3),
            'return_stdev_pct_per_candle' => Money::mul($sd, '100', 4),
            'mean_abs_return_pct_per_candle' => $n ? Money::div(Money::mul(array_reduce($rets, fn ($a, $x) => Money::add($a, Money::abs($x)), '0'), '100'), (string) $n, 4) : '0',
            'coverage' => ['first_t' => Carbon::createFromTimestamp($cs[0]['t'], $tz)->format('Y-m-d H:i'), 'last_t' => Carbon::createFromTimestamp($cs[count($cs) - 1]['t'], $tz)->format('Y-m-d H:i'), 'expected' => (int) ceil(($toT - $fromT) / $secs)],
        ];

        // Touches per level that carried an order
        $levels = [];
        foreach ($rows as $r) {
            $levels[self::dec($r['price'])][] = (int) $r['id'];
        }
        ksort($levels, SORT_STRING);
        $touch = [];
        foreach ($levels as $px => $ids) {
            $episodes = 0;
            $in = false;
            $cross = 0;
            $side = null;
            foreach ($cs as $c) {
                $t = Money::compare($c['l'], (string) $px) <= 0 && Money::compare($c['h'], (string) $px) >= 0;
                if ($t && ! $in) {
                    $episodes++;
                }
                $in = $t;
                $s = Money::compare($c['c'], (string) $px) >= 0 ? 'above' : 'below';
                if ($side !== null && $s !== $side) {
                    $cross++;
                }
                $side = $s;
            }
            $touch[] = ['price' => (string) $px, 'rows' => $ids, 'touch_episodes' => $episodes, 'close_crosses' => $cross];
        }

        // Replay: segments = builds, each live until the next build.
        $segments = [];
        foreach ($builds as $i => $b) {
            $segments[] = [
                'start'  => Carbon::parse($b['at'], $tz)->getTimestamp(),
                'end'    => isset($builds[$i + 1]) ? Carbon::parse($builds[$i + 1]['at'], $tz)->getTimestamp() : $toT + 1,
                'orders' => $b['orders'],
            ];
        }
        $bot     = $this->simulator->run($cs, $segments, $this->spacing, $this->tick, 'bot');
        $classic = $this->simulator->run($cs, $segments, $this->spacing, $this->tick, 'classic');
        $estNet  = function (array $run): string {
            $sum = '0';
            foreach ($run['cycle_list'] as $c) {
                $notional = Money::mul($c['amount'], $c['first_side'] === 'buy' ? $c['buy'] : $c['sell']);
                $sum = Money::add($sum, $this->fees->cycleEstimate($c['first_side'], $notional, $this->spacing, $this->bot)['net']);
            }
            return $sum;
        };

        return [
            'skipped' => false, 'label' => 'ESTIMATE', 'resolution' => $res, 'errors' => $errors, 'stats' => $stats,
            'levels' => $touch,
            'simulation' => [
                'actual_cycles' => $cycles['totals']['count'],
                'bot_rule' => ['cycles' => $bot['cycles'], 'fills' => $bot['fills'], 'open_exits' => $bot['open_exits'], 'est_net' => $estNet($bot)],
                'classic_rearming' => ['cycles' => $classic['cycles'], 'fills' => $classic['fills'], 'open_exits' => $classic['open_exits'], 'est_net' => $estNet($classic)],
                'segments' => count($segments),
                'limits' => [
                    "Candle granularity ({$res}): the intra-candle path is assumed O→L→H→C (up candle) or O→H→L→C (down candle); multiple swings inside one candle are invisible, so both counts are LOWER bounds for a choppy market.",
                    'Fill at touch: a limit fills as soon as the price reaches it — no queue position, no partial fills, no exchange latency; real fills need the price to trade THROUGH the level, so this is optimistic per touch.',
                    'Fees: cycle net uses FeeModel::cycleEstimate at the configured rates on the parent order notional; no slippage.',
                    'Each build is replayed from the first candle that opens after it; unfilled grid orders are dropped at the next build, spawned exits carry over (the bot never cancels exits).',
                    'The bot-rule replay is a calibration check: if it is far from the actual count, distrust the classic number by the same factor.',
                ],
            ],
        ];
    }

    /* ================================================================== */
    /* Anomalies, opinion, summary                                         */
    /* ================================================================== */

    private function flag(string $severity, string $code, string $message, array $rows = [], string $evidence = ''): void
    {
        $this->anomalies[] = ['severity' => $severity, 'code' => $code, 'message' => $message, 'rows' => array_values(array_unique($rows)), 'evidence' => $evidence];
    }

    private function sortedAnomalies(): array
    {
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $a = $this->anomalies;
        usort($a, fn ($x, $y) => ($rank[$x['severity']] <=> $rank[$y['severity']]) ?: strcmp($x['code'], $y['code']));
        return $a;
    }

    private function opinion(array $A): array
    {
        $sev = array_count_values(array_column($A['anomalies'], 'severity'));
        $crit = $sev['critical'] ?? 0;
        $t = $A['cycles']['totals'];
        $be = $A['meta']['fees']['break_even'];
        $sp = Money::mul($this->spacing, '100');
        $items = [];

        if ($crit > 0) {
            $codes = array_unique(array_column(array_filter($A['anomalies'], fn ($a) => $a['severity'] === 'critical'), 'code'));
            $items[] = ['rank' => 1, 'kind' => 'risk', 'text' => "Resolve the {$crit} CRITICAL finding(s) first (" . implode(', ', $codes) . '). Nothing else in this list matters until each is explained or fixed.'];
        }

        $items[] = ['rank' => count($items) + 1, 'kind' => 'worked', 'text' => sprintf(
            'Unit economics: spacing %s%% vs break-even %s%% (buy-first) / %s%% (sell-first); %d booked cycle(s), realized net %s rial, median %s rial per cycle.',
            $sp, $be['buy_first_pct'], $be['sell_first_effective_pct'], $t['count'], FeeModel::roundHalfUp($t['net'], 0), FeeModel::roundHalfUp($t['median_net'] ?? '0', 0)
        )];

        $sim = $A['market']['simulation'] ?? null;
        if ($sim !== null && $sim['bot_rule']['cycles'] > 0) {
            $items[] = ['rank' => count($items) + 1, 'kind' => 'change', 'text' => sprintf(
                'Missed opportunity (ESTIMATE): on the same candles a re-arming grid closes %d cycle(s) vs %d by the bot\'s rule (actual: %d). %s',
                $sim['classic_rearming']['cycles'], $sim['bot_rule']['cycles'], $sim['actual_cycles'],
                $sim['classic_rearming']['cycles'] > $sim['bot_rule']['cycles']
                    ? 'Re-arming exits (each exit fill places its opposite) is the largest lever on cycle count; it also keeps inventory exposure on both sides, so ship it behind a flag and on a paper run first.'
                    : 'Re-arming would not have added cycles in this window — the market did not oscillate across adjacent levels.'
            )];
        } elseif ($sim === null) {
            $items[] = ['rank' => count($items) + 1, 'kind' => 'info', 'text' => 'Market replay skipped (no candles) — re-run without --no-exchange to estimate the re-arming upside.'];
        }

        $inf = $A['infra'];
        if (($inf['totals']['WS_PRIVATE_RECONNECT'] ?? 0) > 0 || ($inf['totals']['WS_PRIVATE_FLAPPING'] ?? 0) > 0) {
            $items[] = ['rank' => count($items) + 1, 'kind' => 'risk', 'text' => "Private WS: {$inf['totals']['WS_PRIVATE_RECONNECT']} reconnect(s), {$inf['totals']['WS_PRIVATE_FLAPPING']} flapping alert(s). The minute poller is the safety net; check exit delays in §2 for fills the WS missed."];
        }
        if (($inf['w4_latency_ms']['n'] ?? 0) > 0) {
            $items[] = ['rank' => count($items) + 1, 'kind' => 'worked', 'text' => "W4 acted {$inf['w4_latency_ms']['n']} time(s); latency median {$inf['w4_latency_ms']['median']} ms, max {$inf['w4_latency_ms']['max']} ms."];
        }

        $bal = $A['balance'];
        if ($bal['residual'] !== null) {
            $zero = Money::compare(Money::abs($bal['residual']['rls']), '1000') <= 0 && Money::compare(Money::abs($bal['residual']['btc']), '0.000001') <= 0;
            $items[] = ['rank' => count($items) + 1, 'kind' => $zero ? 'worked' : 'risk', 'text' => "Balance residual: {$bal['residual']['rls']} rial / {$bal['residual']['btc']} BTC vs start + fills. {$bal['start_nature']['verdict']}"];
        }

        $enough = $t['count'] >= 30 && $crit === 0;
        $items[] = ['rank' => count($items) + 1, 'kind' => 'scaling', 'text' => $enough
            ? 'Scaling: sample size and integrity checks are adequate for a cautious step-up (e.g. ×2), keeping the same spacing; re-audit after the next 30 cycles.'
            : sprintf('Scaling: NOT yet supported by the data — %d cycle(s)%s. Evidence needed: ≥30 booked cycles over ≥2 weeks including at least one ≥5%% directional move, zero critical findings, an exchange reconciliation with no unexplained diffs, a balance residual explained to the rial, and the dust ledger matching.',
                $t['count'], $crit > 0 ? " and {$crit} critical finding(s)" : '')];

        return ['label' => 'OPINION — generated heuristics from the facts above; a human/AI review of this report is the real analysis.', 'items' => $items];
    }

    private function summary(array $A): array
    {
        $sev = array_count_values(array_column($A['anomalies'], 'severity'));
        $t = $A['cycles']['totals'];
        $c = $A['orders']['counts'];
        $p = $A['position'];
        $sim = $A['market']['simulation'] ?? null;
        $w = $A['meta']['window'];
        return [
            "bot {$A['meta']['bot_id']} {$A['meta']['symbol']} — window {$w['from']} → {$w['to']} ({$A['meta']['timezone']})",
            "orders: {$c['total']} rows, {$c['fills']} with fills, {$c['open']} open; roles " . json_encode($c['by_role']),
            "fill paths: " . json_encode($c['fill_paths']),
            "cycles: {$t['count']} booked, recomputed net " . FeeModel::roundHalfUp($t['net'], 0) . ' rial (stored ' . FeeModel::roundHalfUp($t['stored_net'], 0) . ')',
            'fees (rial-valued): ' . FeeModel::roundHalfUp($t['fees'], 0) . ', gross(cash) ' . FeeModel::roundHalfUp($t['gross_cash'], 0) . ', gross(price) ' . FeeModel::roundHalfUp($t['gross_price'], 0),
            'cycle duration: median ' . ($t['duration_s']['median'] ?? '-') . ' s, avg ' . ($t['duration_s']['avg'] ?? '-') . ' s',
            "dust: replayed {$A['dust']['replayed']} vs stored {$A['dust']['bot_config_base_dust']} (Δ {$A['dust']['diff']})",
            'exchange: ' . ($A['exchange']['skipped'] ? 'skipped (--no-exchange)' : "{$A['exchange']['checked']} checked, " . count($A['exchange']['mismatches']) . ' mismatch field(s)'),
            'balance residual: ' . ($A['balance']['residual'] !== null ? "{$A['balance']['residual']['rls']} rial, {$A['balance']['residual']['btc']} BTC" : 'n/a (needs --start-rls/--start-btc and the exchange)'),
            'unrealized: ' . ($p['unrealized'] !== null ? FeeModel::roundHalfUp($p['unrealized'], 0) . " rial at {$p['price_now']} ({$p['price_now_source']})" : 'n/a (no price)'),
            'net P&L (realized + unrealized): ' . ($p['net_pnl'] !== null ? FeeModel::roundHalfUp($p['net_pnl'], 0) : 'n/a'),
            'replay (ESTIMATE): ' . ($sim ? "bot-rule {$sim['bot_rule']['cycles']} vs classic {$sim['classic_rearming']['cycles']} vs actual {$sim['actual_cycles']}" : 'skipped'),
            'infra: ' . $A['infra']['totals']['ERROR'] . ' ERROR, ' . $A['infra']['totals']['CRITICAL'] . ' CRITICAL, ' . $A['infra']['totals']['WS_PRIVATE_RECONNECT'] . ' WS reconnects; W4 median ' . ($A['infra']['w4_latency_ms']['median'] ?? '-') . ' ms',
            'anomalies: ' . ($sev['critical'] ?? 0) . ' critical, ' . ($sev['warning'] ?? 0) . ' warning, ' . ($sev['info'] ?? 0) . ' info',
            "http calls: {$A['meta']['http_calls']}",
        ];
    }

    /* ================================================================== */
    /* Helpers                                                             */
    /* ================================================================== */

    private function activeBudget(): string
    {
        try {
            return $this->bot->activeBudgetIrt();
        } catch (\Throwable) {
            return self::dec($this->bot->getAttributes()['total_capital'] ?? '0');
        }
    }

    /** The EXIT_SIZED line for an exit: the one for its parent logged nearest to the exit row's creation. */
    private function sizingLogFor(array $events, string $exitCreatedAt): ?array
    {
        $tz   = (string) config('app.timezone');
        $at   = Carbon::parse($exitCreatedAt, $tz)->getTimestamp();
        $pick = null;
        $best = PHP_INT_MAX;
        foreach ($events as $e) {
            $d = abs(Carbon::parse($e['ts'], $tz)->getTimestamp() - $at);
            if ($d < $best) {
                $best = $d;
                $pick = $e;
            }
        }
        return $pick;
    }

    private function step(): string
    {
        return $this->qtyDec === 0 ? '1' : '0.' . str_repeat('0', $this->qtyDec - 1) . '1';
    }

    private function isExit(array $r): bool
    {
        return ($r['role'] ?? null) === 'cycle_exit' && $r['paired_order_id'] !== null;
    }

    private function isTerminal(array $r): bool
    {
        return in_array((string) $r['status'], ['filled', 'cancelled', 'failed'], true);
    }

    private function hasExecution(array $r): bool
    {
        return Money::isPositive(self::dec($r['filled_amount'])) && in_array((string) $r['status'], ['filled', 'cancelled', 'partially_filled'], true)
            || ((string) $r['status'] === 'filled');
    }

    /** filled_amount when positive, else amount (CompletedTrade::legQty rule). */
    private function legQty(array $r): string
    {
        $f = self::dec($r['filled_amount']);
        return Money::isPositive($f) ? $f : self::dec($r['amount']);
    }

    /** avg_fill_price → average_fill_price → limit price. */
    private function fillPrice(array $r): string
    {
        foreach (['avg_fill_price', 'average_fill_price'] as $k) {
            $v = self::dec($r[$k] ?? null);
            if (Money::isPositive($v)) {
                return $v;
            }
        }
        return self::dec($r['price']);
    }

    private function exitPrice(string $parentPrice, string $exitSide): string
    {
        $sell = $exitSide === 'sell';
        $raw  = $sell ? Money::mul($parentPrice, Money::add('1', $this->spacing)) : Money::mul($parentPrice, Money::sub('1', $this->spacing));
        return (string) MarketPrecision::alignToTick($raw, (string) $this->tick, $sell);
    }

    private function secs(mixed $a, mixed $b): int
    {
        $tz = (string) config('app.timezone');
        return Carbon::parse((string) $b, $tz)->getTimestamp() - Carbon::parse((string) $a, $tz)->getTimestamp();
    }

    /** Raw attribute row with dates normalised to 'Y-m-d H:i:s' strings. */
    private static function raw(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $a[$k] = $v->format('Y-m-d H:i:s');
            }
        }
        return $a;
    }

    /** DB value → decimal string ('0' for null). Floats (sqlite REAL) are fixed at 12 dp. */
    public static function dec(mixed $v): string
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return '0';
        }
        if (is_float($v)) {
            return Money::trimZeros(sprintf('%.12F', $v));
        }
        if (is_int($v)) {
            return (string) $v;
        }
        $s = trim((string) $v);
        if (! is_numeric($s)) {
            return '0';
        }
        if (stripos($s, 'e') !== false) {
            return Money::trimZeros(sprintf('%.12F', (float) $s));
        }
        return Money::trimZeros($s);
    }

    private static function decOrNull(mixed $v): ?string
    {
        return ($v === null || $v === '') ? null : self::dec($v);
    }

    /** @param list<int|string> $xs */
    public static function stats(array $xs): array
    {
        $n = count($xs);
        if ($n === 0) {
            return ['n' => 0, 'min' => null, 'median' => null, 'avg' => null, 'p95' => null, 'max' => null];
        }
        $s = array_map('strval', $xs);
        usort($s, fn ($a, $b) => Money::compare($a, $b));
        $sum = array_reduce($s, fn ($c, $x) => Money::add($c, $x), '0');
        return [
            'n' => $n, 'min' => $s[0], 'median' => self::median($s), 'avg' => Money::div($sum, (string) $n, 2),
            'p95' => $s[(int) min($n - 1, (int) ceil(0.95 * $n) - 1)], 'max' => $s[$n - 1],
        ];
    }

    /** @param list<string> $xs */
    public static function median(array $xs): ?string
    {
        $n = count($xs);
        if ($n === 0) {
            return null;
        }
        $s = array_map('strval', $xs);
        usort($s, fn ($a, $b) => Money::compare($a, $b));
        return $n % 2 ? $s[intdiv($n, 2)] : Money::div(Money::add($s[$n / 2 - 1], $s[$n / 2]), '2', 2);
    }
}
