<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\FeeModel;
use App\Support\Money;

/**
 * Renders a BotAuditor result into report.md, data.json and the CSV files.
 * Plain ASCII numbers (diff-friendly); rial values derived by the audit are
 * shown rounded half-up to the whole rial, exact values stay in data.json.
 */
final class AuditReportRenderer
{
    /** anomaly code prefix → report section */
    private const SECTION_OF = [
        'KILL_SWITCH' => 1,
        'PRICE_OFF_TICK' => 2, 'AMOUNT_OFF_STEP' => 2, 'EXIT_' => 2, 'DUPLICATE_EXIT' => 2, 'BACKLINK_MISMATCH' => 2,
        'FILL_WITHOUT_EXIT' => 2, 'FEE_' => 2, 'SELL_NOT_ABOVE_BUY' => 2, 'OVERFILL' => 2, 'FILLED_' => 2, 'UNRESOLVED_SUBMISSION' => 2,
        'EXCHANGE_' => 3, 'WALLET_UNAVAILABLE' => 3,
        'CYCLE_' => 4,
        'DUST_' => 5,
        'BALANCE_RESIDUAL' => 6, 'LOCKED_BALANCE_MISMATCH' => 6,
        'LOG_' => 8, 'NO_LOGS' => 8,
        'CANDLES_' => 9, 'NO_CANDLES' => 9,
    ];

    /** @return array<string,string> file name => absolute path */
    public function write(array $A, string $dir): array
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $files = [
            'report.md'  => $this->markdown($A),
            'data.json'  => json_encode($A, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            'orders.csv' => $this->csv(
                ['id', 'created_at', 'nobitex_order_id', 'client_order_id', 'role', 'side', 'price', 'amount', 'filled_amount', 'avg_fill_price',
                    'fee_amount', 'fee_currency', 'fee_source', 'effective_fee_bps', 'status', 'filled_at', 'paired_order_id', 'time_to_fill_s',
                    'fill_path', 'w4_latency_ms', 'exit_delay_s', 'expected_exit_price', 'exit_dust_delta', 'synced', 'checks'],
                array_map(fn ($o) => [
                    $o['id'], $o['created_at'], $o['nobitex_order_id'], $o['client_order_id'], $o['role'], $o['side'], $o['price'], $o['amount'],
                    $o['filled_amount'], $o['avg_fill_price'], $o['fee_amount'], $o['fee_currency'], $o['fee_source'], $o['effective_fee_bps'],
                    $o['status'], $o['filled_at'], $o['paired_order_id'], $o['time_to_fill_s'], $o['fill_path'], $o['w4_latency_ms'],
                    $o['exit_delay_s'], $o['expected_exit_price'], $o['exit_dust_delta'], $o['synced_by_sync_precision'] ? 'yes' : '',
                    implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($o['checks']), $o['checks'])),
                ], $A['orders']['rows'])
            ),
            'cycles.csv' => $this->csv(
                ['id', 'buy_id', 'sell_id', 'first_leg', 'buy_qty', 'buy_px', 'sell_qty', 'sell_px', 'buy_fee', 'buy_fee_currency', 'sell_fee',
                    'sell_fee_currency', 'gross_cash', 'gross_price', 'fee_rial', 'net', 'stored_net', 'stored_fee', 'base_residual',
                    'stored_base_residual', 'duration_s', 'closed_at', 'diff'],
                array_map(fn ($c) => isset($c['net']) ? [
                    $c['id'], $c['buy_id'], $c['sell_id'], $c['first_leg'], $c['buy_qty'], $c['buy_px'], $c['sell_qty'], $c['sell_px'],
                    $c['buy_fee'], $c['buy_fee_currency'], $c['sell_fee'], $c['sell_fee_currency'], $c['gross_cash'], $c['gross_price'],
                    $c['fee_rial'], $c['net'], $c['stored_net'], $c['stored_fee'], $c['base_residual'], $c['stored_base_residual'],
                    $c['duration_s'], $c['closed_at'], implode('; ', $c['diff']),
                ] : [$c['id'], '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', $c['error'] ?? ''], $A['cycles']['rows'])
            ),
            'events.csv' => $this->csv(['ts', 'source', 'level', 'event', 'row_ids', 'detail'], $A['events'] ?? []),
        ];
        $paths = [];
        foreach ($files as $name => $content) {
            $path = rtrim($dir, '/') . '/' . $name;
            file_put_contents($path, $content);
            $paths[$name] = $path;
        }
        return $paths;
    }

    public function markdown(array $A): string
    {
        $m   = $A['meta'];
        $bySection = [];
        foreach ($A['anomalies'] as $a) {
            $bySection[$this->sectionOf($a['code'])][] = $a;
        }
        $L = [];
        $L[] = "# Bot {$m['bot_id']} audit — {$m['symbol']}";
        $L[] = '';
        $L[] = "Generated {$m['generated_at']} ({$m['timezone']}). Window **{$m['window']['from']} → {$m['window']['to']}** (from: {$m['window']['from_source']}; to: {$m['window']['to_source']}).";
        $L[] = '';
        $L[] = 'Read-only: no DB writes, no order placement/cancel. Exchange calls: ' . ($A['meta']['options']['no_exchange'] ? 'none (`--no-exchange`).' : "{$m['http_calls']} (order status, wallet, candles; ≥{$m['options']['rate_ms']} ms apart).");
        $L[] = '';
        $L[] = '**Labels.** FACT = computed from DB rows / log lines / exchange responses (source named). ESTIMATE = model output, labelled. OPINION = §11 only.';
        $L[] = '';
        $L[] = '## Summary';
        $L[] = '';
        $L[] = '```';
        foreach ($A['summary'] as $s) {
            $L[] = $s;
        }
        $L[] = '```';
        $L[] = '';
        $L[] = '| setting | value | source |';
        $L[] = '|---|---|---|';
        $L[] = "| price tick | {$m['precision']['tick']} | {$m['precision']['tick_source']} |";
        $L[] = "| qty decimals | {$m['precision']['qty_decimals']} | {$m['precision']['qty_source']} |";
        $L[] = '| spacing | ' . $this->pct($m['spacing_fraction']) . ' | bot_configs.grid_spacing |';
        $L[] = "| fee buy / sell | {$m['fees']['buy_bps']} bps {$m['fees']['buy_currency']} / {$m['fees']['sell_bps']} bps {$m['fees']['sell_currency']} | FeeModel (bot override → config) |";
        $L[] = "| break-even spacing buy-first / sell-first | {$m['fees']['break_even']['buy_first_pct']}% / {$m['fees']['break_even']['sell_first_effective_pct']}% | FeeModel::breakEvenSpacing |";
        $L[] = '| log lines kept / read | ' . $m['log_lines_in_window'] . ' / ' . $m['log_lines_read'] . ' (testing.* skipped: ' . $m['log_lines_testing_skipped'] . '; routine INFO dropped) | ' . count($m['log_files']) . ' file(s) |';
        $L[] = '| rows synced by grid:sync-precision | ' . ($m['synced_rows'] ? implode(',', $m['synced_rows']) : 'none found') . ' | PRECISION_ROW_SYNCED logs + --synced |';
        $L[] = '';

        $this->s1($L, $A['timeline'], $bySection[1] ?? []);
        $this->s2($L, $A['orders'], $bySection[2] ?? []);
        $this->s3($L, $A['exchange'], $bySection[3] ?? []);
        $this->s4($L, $A['cycles'], $bySection[4] ?? []);
        $this->s5($L, $A['dust'], $bySection[5] ?? []);
        $this->s6($L, $A['balance'], $bySection[6] ?? []);
        $this->s7($L, $A['position']);
        $this->s8($L, $A['infra'], $bySection[8] ?? []);
        $this->s9($L, $A['market'], $bySection[9] ?? []);

        $L[] = '## 10. Anomalies (consolidated)';
        $L[] = '';
        if ($A['anomalies'] === []) {
            $L[] = 'None.';
        } else {
            $L[] = '| # | severity | code | finding | rows | § |';
            $L[] = '|---|---|---|---|---|---|';
            foreach ($A['anomalies'] as $i => $a) {
                $L[] = '| ' . ($i + 1) . " | {$a['severity']} | {$a['code']} | " . $this->esc($a['message']) . ' | ' . implode(',', $a['rows']) . ' | ' . $this->sectionOf($a['code']) . ' |';
            }
        }
        $L[] = '';
        $L[] = '## 11. Opinion';
        $L[] = '';
        $L[] = "_{$A['opinion']['label']}_";
        $L[] = '';
        foreach ($A['opinion']['items'] as $it) {
            $L[] = "{$it['rank']}. **{$it['kind']}** — {$it['text']}";
        }
        $L[] = '';

        return implode("\n", $L);
    }

    private function s1(array &$L, array $t, array $an): void
    {
        $L[] = '## 1. Bot & timeline';
        $L[] = '';
        $L[] = '**Method.** Config = raw bot_configs row. Builds = non-exit grid_orders clustered by role and creation time (gap > 120 s starts a new build). Budget = REBALANCE_EFFECTIVE_BUDGET (bot_id) and GRID_PLAN (symbol + time, it has no bot_id) logged ≤ 300 s before the build. Risk events = KILL_SWITCH / stop-loss / drawdown log lines for this bot.';
        $L[] = '';
        $c = $t['config'];
        $keys = ['name', 'symbol', 'simulation', 'is_active', 'total_capital', 'active_capital_percent', 'grid_spacing', 'grid_levels', 'levels', 'mode',
            'buy_fee_bps', 'sell_fee_bps', 'base_dust', 'stop_loss_percent', 'max_drawdown_percent', 'grid_center_price', 'center_price',
            'started_at', 'stopped_at', 'stop_reason', 'last_rebalance_at', 'rebalance_count', 'open_cycles_count', 'capital_locked_irt', 'init_status'];
        $L[] = '| field | value |';
        $L[] = '|---|---|';
        foreach ($keys as $k) {
            if (array_key_exists($k, $c)) {
                $L[] = "| {$k} | " . $this->esc($this->v($c[$k])) . ' |';
            }
        }
        $L[] = "| (derived) active budget | {$t['active_budget_irt']} |";
        $L[] = "| (derived) first order at | {$this->v($t['first_order_at'])} |";
        $L[] = '';
        $L[] = '_Full row in data.json → timeline.config._';
        $L[] = '';
        $L[] = '| # | at | role | orders (buy/sell) | Σ notional | effective budget (log) | GRID_PLAN budget / mid | prices |';
        $L[] = '|---|---|---|---|---|---|---|---|';
        foreach ($t['builds'] as $i => $b) {
            $eb = $b['effective_budget'] ? ($b['effective_budget']['effective_budget'] ?? '?') . ' (active ' . ($b['effective_budget']['active_budget'] ?? '?') . ', locked ' . ($b['effective_budget']['locked_capital'] ?? '?') . ')' : '—';
            $gp = $b['grid_plan'] ? ($b['grid_plan']['budget_irt'] ?? '?') . ' / ' . ($b['grid_plan']['mid'] ?? '?') : '—';
            $L[] = '| ' . ($i + 1) . " | {$b['at']} | {$b['role']} | {$b['buys']}/{$b['sells']} | {$b['notional']} | {$eb} | {$gp} | " . implode(' ', $b['prices']) . ' |';
        }
        $L[] = '';
        $L[] = 'Rebalances with `rebalance_applied=true` in logs: ' . ($t['rebalances_applied_logged'] ? implode(', ', $t['rebalances_applied_logged']) : 'none');
        $L[] = '';
        $L[] = 'Risk events (kill switch / stop-loss / drawdown): ' . ($t['risk_events'] === [] ? 'none in the window.' : '');
        foreach ($t['risk_events'] as $r) {
            $L[] = "- {$r['ts']} {$r['level']} {$r['event']} " . $this->esc(json_encode($r['context'], JSON_UNESCAPED_SLASHES));
        }
        if ($t['activity_log_counts'] !== []) {
            $L[] = '';
            $L[] = 'bot_activity_logs in window: ' . implode(', ', array_map(fn ($r) => "{$r['action_type']}/{$r['level']}={$r['n']}", $t['activity_log_counts']));
        }
        $this->anomalyBlock($L, $an);
    }

    private function s2(array &$L, array $o, array $an): void
    {
        $L[] = '## 2. Order ledger';
        $L[] = '';
        $L[] = '**Method.** Every grid_orders row of the bot. Checks: (a) price on the market tick; (b) amount on the qty step; (c) exit price = parent LIMIT price × (1 ± spacing), side-safe (sell up, buy down), recomputed here — the bot prices exits from the limit, the value from the average fill is noted when it differs; (d) exactly one live exit per fill; (e) fee currency by side (buy → base, sell → quote); (f) effective fee = fee / (filled or filled×avg) in bps vs configured, drift > `drift_warn_bps` flagged, estimated fees not verifiable; (g) the sell leg is above its paired buy. Fill path: W4 when a WS_EVENT_ACTED line for the row attempted the pair or saw it filled within 3 s of filled_at; otherwise the minute poller. Exit delay = exit.created_at − parent.filled_at (DB second resolution).';
        $L[] = '';
        $c = $o['counts'];
        $L[] = "Rows {$c['total']}; with fills {$c['fills']}; open {$c['open']}. By status " . $this->kv($c['by_status']) . '. By role ' . $this->kv($c['by_role']) . '. Fill paths ' . $this->kv($c['fill_paths']) . '.';
        $d = $o['exit_delay_s'];
        $L[] = '';
        $L[] = "Exit creation delay (s): n={$d['n']} min={$this->v($d['min'])} median={$this->v($d['median'])} p95={$this->v($d['p95'])} max={$this->v($d['max'])}.";
        $L[] = '';
        $L[] = '| id | created | role | side | price | amount | filled | avg px | fee | cur | src | eff bps | status | filled_at | pair | ttf s | path | lat ms | exit delay s | a | b | c | d | e | f | g |';
        $L[] = '|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|';
        foreach ($o['rows'] as $r) {
            $ck = $r['checks'];
            $L[] = "| {$r['id']}" . ($r['synced_by_sync_precision'] ? '*' : '') . " | {$r['created_at']} | {$this->v($r['role'])} | {$r['side']} | {$r['price']} | {$r['amount']} | {$r['filled_amount']} | {$this->v($r['avg_fill_price'])} | {$this->v($r['fee_amount'])} | {$this->v($r['fee_currency'])} | {$this->v($r['fee_source'])} | {$this->v($r['effective_fee_bps'])} | {$r['status']} | {$this->v($r['filled_at'])} | {$this->v($r['paired_order_id'])} | {$this->v($r['time_to_fill_s'])} | {$this->v($r['fill_path'])} | {$this->v($r['w4_latency_ms'])} | {$this->v($r['exit_delay_s'])} | "
                . implode(' | ', array_map(fn ($k) => $this->esc($this->short($ck[$k] ?? '')), ['a_tick', 'b_step', 'c_exit_price', 'd_one_exit', 'e_fee_currency', 'f_fee_rate', 'g_sell_above_buy'])) . ' |';
        }
        $L[] = '';
        $L[] = '`*` = row synced by grid:sync-precision. Exchange ids, client order ids and full check texts: orders.csv.';
        $this->anomalyBlock($L, $an);
    }

    private function s3(array &$L, array $x, array $an): void
    {
        $L[] = '## 3. Exchange reconciliation';
        $L[] = '';
        if ($x['skipped']) {
            $L[] = 'Skipped (`--no-exchange`).';
            $L[] = '';
            return;
        }
        $L[] = '**Method.** For every row with a real nobitex id: NobitexService::getOrdersStatus([id]) one at a time (≥ 300 ms apart); status, amount, filled, price, fee and averagePrice compared with the DB. Rows synced by grid:sync-precision (bot 48: 275–279) had amount/price rewritten to the exchange values; any residual amount/price diff on them is historic and listed as info, not hidden.';
        $L[] = '';
        $L[] = "Checked {$x['checked']} order(s); unavailable {$x['unavailable']}; mismatching fields " . count($x['mismatches']) . '.';
        $L[] = '';
        if ($x['mismatches'] !== []) {
            $L[] = '| row | nobitex id | field | DB | exchange | severity | note |';
            $L[] = '|---|---|---|---|---|---|---|';
            foreach ($x['mismatches'] as $mm) {
                $L[] = "| {$mm['id']} | {$mm['nobitex_order_id']} | {$mm['field']} | {$this->v($mm['db'])} | {$this->v($mm['exchange'])} | {$mm['severity']} | {$this->esc($mm['note'])} |";
            }
        } else {
            $L[] = 'All checked rows match the exchange.';
        }
        $this->anomalyBlock($L, $an);
    }

    private function s4(array &$L, array $c, array $an): void
    {
        $L[] = '## 4. Cycles (completed_trades), recomputed independently';
        $L[] = '';
        $L[] = '**Method.** From the two legs only: qty = filled_amount (else amount), price = avg_fill_price (else limit), fee = stored per-order fee (else an estimate at the configured rate). gross(cash) = sell notional − buy notional; gross(price) = (sell px − buy px) × sell qty; fee(rial) = quote fees + base fees × buy px; base_residual = buy qty − sell qty − base fees; net = gross(cash) − quote fees + base_residual × buy px (the BTC left behind valued at its purchase price — same accounting as CompletedTrade). Flags: |Δnet| or |Δfee| > 1 rial, |Δresidual| > 1e-10 BTC. Duration = earlier leg fill → later leg fill.';
        $L[] = '';
        $L[] = '| trade | buy/sell | first | buy qty @ px | sell qty @ px | gross cash | gross price | fee (rial) | net | stored net | residual BTC | duration | diff |';
        $L[] = '|---|---|---|---|---|---|---|---|---|---|---|---|---|';
        foreach ($c['rows'] as $r) {
            if (! isset($r['net'])) {
                $L[] = "| {$r['id']} | — | — | — | — | — | — | — | — | — | — | — | {$r['error']} |";
                continue;
            }
            $L[] = "| {$r['id']} | {$r['buy_id']}/{$r['sell_id']} | {$r['first_leg']} | {$r['buy_qty']} @ {$r['buy_px']} | {$r['sell_qty']} @ {$r['sell_px']} | "
                . $this->r0($r['gross_cash']) . ' | ' . $this->r0($r['gross_price']) . ' | ' . $this->r0($r['fee_rial']) . ' | ' . $this->r0($r['net']) . ' | ' . $this->r0($r['stored_net'])
                . " | {$r['base_residual']} | " . $this->dur($r['duration_s']) . ' | ' . ($r['diff'] ? $this->esc(implode('; ', $r['diff'])) : 'ok') . ' |';
        }
        $t = $c['totals'];
        $L[] = '';
        $L[] = "Totals: **{$t['count']} cycles**, net **" . $this->r0($t['net']) . '** rial (stored ' . $this->r0($t['stored_net']) . '), fees ' . $this->r0($t['fees']) . ', gross(cash) ' . $this->r0($t['gross_cash'])
            . ', gross(price) ' . $this->r0($t['gross_price']) . ", Σ base residual {$t['base_residual']} BTC. Avg net " . $this->r0($t['avg_net']) . ', median ' . $this->r0($t['median_net'] ?? '0')
            . '. Duration avg ' . $this->dur($t['duration_s']['avg']) . ', median ' . $this->dur($t['duration_s']['median']) . '. Avg net per cycle per 1M rial deployed (buy notional): ' . $this->v($t['avg_net_per_cycle_per_1m_deployed']) . ' rial. First leg: ' . $this->kv($t['by_first_leg']) . '.';
        if ($c['not_booked_exit_ids'] !== []) {
            $L[] = '';
            $L[] = 'Filled exits with NO booking: ' . implode(', ', $c['not_booked_exit_ids']);
        }
        $this->anomalyBlock($L, $an);
    }

    private function s5(array &$L, array $d, array $an): void
    {
        $L[] = '## 5. Base-dust ledger';
        $L[] = '';
        $L[] = '**Method.** Replayed from 0 in time order through every dust move the code makes: (1) an exit SELL sized from a buy fill moves dust by credited − exit amount (credited = filled − base fee; exit amount from the EXIT_SIZED log when present, else the row); (2) an exit BUY fill moves it by (filled − base fee) − (parent sold + parent base fee); (3) a cancelled partial absorbed into dust moves it by its net base change. Each recomputed step is compared with the row\'s stored exit_dust_delta, the EXIT_SIZED dust_before/after the bot logged is compared with the replay at that point, and the end value with bot_configs.base_dust.';
        $L[] = '';
        $L[] = '| at | row | kind | delta (recomputed) | stored | dust before → after | EXIT_SIZED log before → after |';
        $L[] = '|---|---|---|---|---|---|---|';
        foreach ($d['steps'] as $s) {
            $log = isset($s['log_dust_before']) && $s['log_dust_before'] !== null ? "{$s['log_dust_before']} → {$s['log_dust_after']}" : '—';
            $L[] = "| {$s['at']} | {$s['row']} | {$s['kind']} | {$s['delta']} | {$this->v($s['stored'])} | {$s['dust_before']} → {$s['dust_after']} | {$log} |";
        }
        $L[] = '';
        $L[] = "Replayed **{$d['replayed']}** BTC; Σ stored exit_dust_delta {$d['stored_sum_exit_dust_delta']}; bot_configs.base_dust **{$d['bot_config_base_dust']}**; Δ {$d['diff']} (one qty step = {$d['step_size']}).";
        $this->anomalyBlock($L, $an);
    }

    private function s6(array &$L, array $b, array $an): void
    {
        $L[] = '## 6. Balance reconciliation';
        $L[] = '';
        $L[] = '**Method.** ' . $b['method'];
        $L[] = '';
        $L[] = "Fills counted {$b['fills_counted']} (with estimated fee: {$b['fills_with_estimated_fee']}). Σ flows: rls {$b['delta']['rls']}, btc {$b['delta']['btc']}.";
        $L[] = '';
        $L[] = '| | rls | btc |';
        $L[] = '|---|---|---|';
        if ($b['start'] !== null) {
            $L[] = "| start (--start-*, at {$b['start']['at']}) | {$b['start']['rls']} | {$b['start']['btc']} |";
            $L[] = "| expected now = start + flows | {$b['expected_now']['rls']} | {$b['expected_now']['btc']} |";
        }
        if ($b['wallet'] !== null) {
            $w = $b['wallet'];
            $L[] = "| wallet total (getBalances) | {$w['rls']['total']} | {$w['btc']['total']} |";
            $L[] = "| wallet available | {$w['rls']['available']} | {$w['btc']['available']} |";
            $L[] = "| wallet locked | {$w['rls']['locked']} | {$w['btc']['locked']} |";
        }
        $L[] = "| expected locked, this bot's open orders | {$b['expected_locked_this_bot']['rls']} | {$b['expected_locked_this_bot']['btc']} |";
        $L[] = "| expected locked, all bots' open orders | {$b['expected_locked_all_bots']['rls']} | {$b['expected_locked_all_bots']['btc']} |";
        if (isset($b['locked_vs_open_orders'])) {
            $L[] = "| wallet locked − open orders (all bots) | {$b['locked_vs_open_orders']['rls']} | {$b['locked_vs_open_orders']['btc']} |";
        }
        if ($b['residual'] !== null) {
            $L[] = "| **residual = wallet total − expected** | **{$b['residual']['rls']}** | **{$b['residual']['btc']}** |";
        }
        $L[] = '';
        if ($b['residual'] !== null) {
            $L[] = 'Possible explanations for a residual: ' . implode('; ', $b['residual_explanations']) . '.';
            $L[] = '';
            $L[] = "Start snapshot total vs activeBalance ({$b['start_nature']['label']}): {$b['start_nature']['verdict']} {$b['start_nature']['note']}";
        } elseif ($b['start'] === null) {
            $L[] = 'No `--start-rls/--start-btc` given: only the flows and locks are shown.';
        } else {
            $L[] = 'Wallet not fetched (`--no-exchange` or error): the residual cannot be computed.';
        }
        $this->anomalyBlock($L, $an);
    }

    private function s7(array &$L, array $p): void
    {
        $L[] = '## 7. Open position & unrealized P&L';
        $L[] = '';
        $L[] = '**Method.** Open exit SELLs = long cycles: BTC held = exit remaining, cost basis = parent buy notional pro-rated to the held BTC; value at the current price, unrealized after an estimated exit fee. Open exit BUYs = short cycles: proceeds of the parent sell after fee vs the cost of buying back the inventory-restoring amount now. Grid sells that are not exits are BTC inventory on the book, not cycles. Net P&L = realized (§4 recomputed) + unrealized.';
        $L[] = '';
        $L[] = "Current price: **{$this->v($p['price_now'])}** ({$p['price_now_source']}).";
        $L[] = '';
        foreach ($p['open_long_cycles'] as $r) {
            $L[] = "- long cycle: exit {$r['exit_id']} (parent {$r['parent_id']}): {$r['btc_held']} BTC bought @ {$r['buy_px']}, cost " . $this->r0($r['cost_basis']) . ', exit @ ' . $r['exit_px'] . (isset($r['value_now']) ? ', value now ' . $this->r0($r['value_now']) . ', unrealized ' . $this->r0($r['unrealized_after_est_exit_fee']) : '');
        }
        foreach ($p['open_short_cycles'] as $r) {
            $L[] = "- short cycle: exit {$r['exit_id']} (parent {$r['parent_id']}): sold {$r['btc_owed']} BTC @ {$r['sell_px']}, proceeds " . $this->r0($r['proceeds']) . ', exit @ ' . $r['exit_px'] . (isset($r['unrealized']) ? ', buy-back now ' . $this->r0($r['buy_back_cost_now']) . ', unrealized ' . $this->r0($r['unrealized']) : '');
        }
        if ($p['open_long_cycles'] === [] && $p['open_short_cycles'] === []) {
            $L[] = '- no open cycles.';
        }
        $L[] = '';
        $L[] = '| item | value |';
        $L[] = '|---|---|';
        $L[] = "| BTC held by open long cycles | {$p['btc_held_open_cycles']} |";
        $L[] = '| cost basis of that BTC | ' . $this->r0($p['cost_basis_open_cycles']) . ' |';
        $L[] = "| BTC owed by open short cycles | {$p['btc_owed_open_short_cycles']} |";
        $L[] = "| grid-sell BTC on the book (rows " . implode(',', $p['grid_sell_inventory_on_book']['rows']) . ") | {$p['grid_sell_inventory_on_book']['btc']} |";
        $L[] = '| realized net | ' . $this->r0($p['realized_net']) . ' |';
        $L[] = '| unrealized | ' . ($p['unrealized'] !== null ? $this->r0($p['unrealized']) : 'n/a') . ' |';
        $L[] = '| **net P&L** | **' . ($p['net_pnl'] !== null ? $this->r0($p['net_pnl']) : 'n/a') . '** |';
        $L[] = '';
        if ($p['benchmark'] !== null) {
            $bm = $p['benchmark'];
            $L[] = "Buy-and-hold benchmark ({$bm['label']}): price {$bm['price_start']} → {$bm['price_now']}. Holding the active budget fully in BTC would have made " . $this->r0($bm['active_budget_all_btc_pnl']) . ' rial.';
            if (isset($bm['hold_equity_now'])) {
                $L[] = '';
                $L[] = '| equity (rial, BTC at price_now) | value |';
                $L[] = '|---|---|';
                $L[] = '| start (BTC at price_start) | ' . $this->r0($bm['start_equity']) . ' |';
                $L[] = '| hold start balances | ' . $this->r0($bm['hold_equity_now']) . ' |';
                $L[] = '| bot, by ledger (start + flows) | ' . $this->r0($bm['bot_ledger_equity_now']) . ' |';
                $L[] = '| **bot − hold (ledger)** | **' . $this->r0($bm['bot_minus_hold_ledger']) . '** |';
                if (isset($bm['wallet_equity_now'])) {
                    $L[] = '| wallet now | ' . $this->r0($bm['wallet_equity_now']) . ' |';
                    $L[] = '| wallet − hold | ' . $this->r0($bm['wallet_minus_hold']) . ' |';
                }
            }
        } else {
            $L[] = 'Buy-and-hold benchmark: n/a (needs candles).';
        }
        $L[] = '';
    }

    private function s8(array &$L, array $i, array $an): void
    {
        $L[] = '## 8. Infrastructure health';
        $L[] = '';
        $L[] = '**Method.** Every `*.log` and rotated `*.log.gz` under the log dir (gzopen), lines inside the window, `testing.*` ignored. ' . $i['note'];
        $L[] = '';
        $cats = array_keys($i['totals']);
        $L[] = '| day | ' . implode(' | ', $cats) . ' |';
        $L[] = '|---|' . str_repeat('---|', count($cats));
        foreach ($i['per_day'] as $day => $row) {
            $L[] = "| {$day} | " . implode(' | ', array_map(fn ($c) => (string) ($row[$c] ?? 0), $cats)) . ' |';
        }
        $L[] = '| **total** | ' . implode(' | ', array_map(fn ($c) => (string) $i['totals'][$c], $cats)) . ' |';
        $L[] = '';
        $w = $i['w4_latency_ms'];
        $L[] = "W4 latency_ms (WS_EVENT_ACTED): n={$w['n']} min={$this->v($w['min'])} median={$this->v($w['median'])} p95={$this->v($w['p95'])} max={$this->v($w['max'])}. Outcomes " . $this->kv($i['w4_outcomes']) . '.';
        if ($i['top_errors'] !== []) {
            $L[] = '';
            $L[] = 'Top ERROR/CRITICAL events: ' . implode('; ', array_map(fn ($k, $n) => $this->esc($k) . " ×{$n}", array_keys($i['top_errors']), $i['top_errors']));
        }
        $this->anomalyBlock($L, $an);
    }

    private function s9(array &$L, array $m, array $an): void
    {
        $L[] = '## 9. Market context & missed opportunity — ESTIMATE';
        $L[] = '';
        if ($m['skipped']) {
            $L[] = "Skipped: {$m['reason']}.";
            $this->anomalyBlock($L, $an);
            return;
        }
        $s = $m['stats'];
        $L[] = "**Method.** {$m['resolution']} candles from GET /market/udf/history (TOMAN ×10 → rial), window-chunked. Touches = runs of consecutive candles whose [low, high] contains the level; crosses = close-to-close side changes. Replay: see limits below.";
        $L[] = '';
        $L[] = "Candles {$s['candles']} (expected ≈{$s['coverage']['expected']}, {$s['coverage']['first_t']} → {$s['coverage']['last_t']} UTC-epoch based). Open {$s['first_open']} → close {$s['last_close']} ({$s['change_pct']}%); high {$s['high']}, low {$s['low']}, range {$s['range_pct']}%; σ(return) {$s['return_stdev_pct_per_candle']}% per candle; mean |return| {$s['mean_abs_return_pct_per_candle']}%.";
        $L[] = '';
        $L[] = '| level | rows | touch episodes | close crosses |';
        $L[] = '|---|---|---|---|';
        foreach ($m['levels'] as $l) {
            $L[] = "| {$l['price']} | " . implode(',', $l['rows']) . " | {$l['touch_episodes']} | {$l['close_crosses']} |";
        }
        $sim = $m['simulation'];
        $L[] = '';
        $L[] = '| policy (same candles, same starting orders) | cycles | fills | open exits at end | est. net (rial) |';
        $L[] = '|---|---|---|---|---|';
        $L[] = "| actual (completed_trades) | {$sim['actual_cycles']} | — | — | — |";
        $L[] = "| replay: bot rule (exit fills do not re-arm) | {$sim['bot_rule']['cycles']} | {$sim['bot_rule']['fills']} | {$sim['bot_rule']['open_exits']} | " . $this->r0($sim['bot_rule']['est_net']) . ' |';
        $L[] = "| replay: classic re-arming grid | {$sim['classic_rearming']['cycles']} | {$sim['classic_rearming']['fills']} | {$sim['classic_rearming']['open_exits']} | " . $this->r0($sim['classic_rearming']['est_net']) . ' |';
        $L[] = '';
        $L[] = 'Limits:';
        foreach ($sim['limits'] as $x) {
            $L[] = "- {$x}";
        }
        $this->anomalyBlock($L, $an);
    }

    private function anomalyBlock(array &$L, array $an): void
    {
        $L[] = '';
        if ($an === []) {
            $L[] = '_Anomalies: none._';
        } else {
            $L[] = '**Anomalies:**';
            foreach ($an as $a) {
                $L[] = "- [{$a['severity']}] {$a['code']}: " . $a['message'];
            }
        }
        $L[] = '';
    }

    private function sectionOf(string $code): int
    {
        foreach (self::SECTION_OF as $prefix => $sec) {
            if (str_starts_with($code, $prefix)) {
                return $sec;
            }
        }
        return 10;
    }

    private function csv(array $header, array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fputcsv($h, $header, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (is_array($v) ? json_encode($v) : (string) ($v ?? '')), $r), ',', '"', '');
        }
        rewind($h);
        $s = stream_get_contents($h);
        fclose($h);
        return (string) $s;
    }

    private function r0(?string $v): string
    {
        return $v === null ? 'n/a' : FeeModel::roundHalfUp($v, 0);
    }

    private function pct(string $fraction): string
    {
        return Money::trimZeros(Money::mul($fraction, '100', 4)) . '%';
    }

    private function dur(int|string|null $s): string
    {
        if ($s === null) {
            return '—';
        }
        $s = (int) $s;
        return sprintf('%dh%02dm', intdiv($s, 3600), intdiv($s % 3600, 60));
    }

    private function kv(array $a): string
    {
        return $a === [] ? '—' : implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($a), $a));
    }

    private function v(mixed $v): string
    {
        if ($v === null) {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return (string) $v;
    }

    private function short(string $s): string
    {
        return mb_strlen($s) > 48 ? mb_substr($s, 0, 45) . '...' : $s;
    }

    private function esc(string $s): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $s);
    }
}
