# Bot 48 audit — BTCIRT

Generated 2026-10-09 13:29:32 (UTC). Window **2026-10-02 22:41:00 → 2026-10-09 12:00:00** (from: first order / started_at; to: --to).

Read-only: no DB writes, no order placement/cancel. Exchange calls: 17 (order status, wallet, candles; ≥300 ms apart).

**Labels.** FACT = computed from DB rows / log lines / exchange responses (source named). ESTIMATE = model output, labelled. OPINION = §11 only.

## Summary

```
bot 48 BTCIRT — window 2026-10-02 22:41:00 → 2026-10-09 12:00:00 (UTC)
orders: 15 rows, 12 with fills, 2 open; roles {"initial_grid":4,"rebalance":4,"cycle_exit":7}
fill paths: {"W4":3,"poller":9}
cycles: 6 booked, recomputed net 590020 rial (stored 590020)
fees (rial-valued): 308333, gross(cash) 450118, gross(price) 898353
cycle duration: median 43200 s, avg 42800 s
dust: replayed 0.0000011671 vs stored 0.0000011671 (Δ 0)
exchange: 15 checked, 2 mismatch field(s)
balance residual: 0 rial, 0 BTC
unrealized: 287606 rial at 224055554700 (last candle close (1h))
net P&L (realized + unrealized): 877626
replay (ESTIMATE): bot-rule 6 vs classic 36 vs actual 6
infra: 1 ERROR, 0 CRITICAL, 2 WS reconnects; W4 median 850 ms
anomalies: 1 critical, 3 warning, 3 info
http calls: 17
```

| setting | value | source |
|---|---|---|
| price tick | 10 | config |
| qty decimals | 6 | config |
| spacing | 1.5% | bot_configs.grid_spacing |
| fee buy / sell | 25 bps base / 25 bps quote | FeeModel (bot override → config) |
| break-even spacing buy-first / sell-first | 0.5019% / 0.4994% | FeeModel::breakEvenSpacing |
| log lines kept / read | 20 / 21 (testing.* skipped: 1; routine INFO dropped) | 7 file(s) |
| rows synced by grid:sync-precision | 104 | PRECISION_ROW_SYNCED logs + --synced |

## 1. Bot & timeline

**Method.** Config = raw bot_configs row. Builds = non-exit grid_orders clustered by role and creation time (gap > 120 s starts a new build). Budget = REBALANCE_EFFECTIVE_BUDGET (bot_id) and GRID_PLAN (symbol + time, it has no bot_id) logged ≤ 300 s before the build. Risk events = KILL_SWITCH / stop-loss / drawdown log lines for this bot.

| field | value |
|---|---|
| name | bot48 |
| symbol | BTCIRT |
| simulation | 0 |
| is_active | 1 |
| total_capital | 50000000 |
| active_capital_percent | 80 |
| grid_spacing | 1.5 |
| grid_levels | 4 |
| levels | 4 |
| mode | buy |
| buy_fee_bps | — |
| sell_fee_bps | — |
| base_dust | 0.0000011671 |
| stop_loss_percent | 5 |
| max_drawdown_percent | — |
| grid_center_price | — |
| center_price | — |
| started_at | 2026-10-02 22:41:00 |
| stopped_at | — |
| stop_reason | — |
| last_rebalance_at | — |
| rebalance_count | 0 |
| open_cycles_count | 1 |
| capital_locked_irt | 9968200 |
| init_status | — |
| (derived) active budget | 40000000 |
| (derived) first order at | 2026-10-02 22:41:00 |

_Full row in data.json → timeline.config._

| # | at | role | orders (buy/sell) | Σ notional | effective budget (log) | GRID_PLAN budget / mid | prices |
|---|---|---|---|---|---|---|---|
| 1 | 2026-10-02 22:41:00 | initial_grid | 2/2 | 40284942 | — | 40000000 / 225000000000 | buy@221620000000 buy@218290000000 sell@228380000000 sell@231800000000 |
| 2 | 2026-10-05 10:00:00 | rebalance | 2/2 | 40021450 | 40000000 (active 40000000, locked 0) | 40000000 / 220000000000 | buy@216700000000 buy@213450000000 sell@223300000000 sell@226650000000 |

Rebalances with `rebalance_applied=true` in logs: 2026-10-05 10:00:30

Risk events (kill switch / stop-loss / drawdown): none in the window.

_Anomalies: none._

## 2. Order ledger

**Method.** Every grid_orders row of the bot. Checks: (a) price on the market tick; (b) amount on the qty step; (c) exit price = parent LIMIT price × (1 ± spacing), side-safe (sell up, buy down), recomputed here — the bot prices exits from the limit, the value from the average fill is noted when it differs; (d) exactly one live exit per fill; (e) fee currency by side (buy → base, sell → quote); (f) effective fee = fee / (filled or filled×avg) in bps vs configured, drift > `drift_warn_bps` flagged, estimated fees not verifiable; (g) the sell leg is above its paired buy. Fill path: W4 when a WS_EVENT_ACTED line for the row attempted the pair or saw it filled within 3 s of filled_at; otherwise the minute poller. Exit delay = exit.created_at − parent.filled_at (DB second resolution).

Rows 15; with fills 12; open 2. By status filled=12, cancelled=1, placed=2. By role initial_grid=4, rebalance=4, cycle_exit=7. Fill paths W4=3, poller=9.

Exit creation delay (s): n=7 min=1 median=2 p95=5 max=5.

| id | created | role | side | price | amount | filled | avg px | fee | cur | src | eff bps | status | filled_at | pair | ttf s | path | lat ms | exit delay s | a | b | c | d | e | f | g |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 101 | 2026-10-02 22:41:00 | initial_grid | buy | 221620000000 | 0.000045 | 0.000045 | 221620000000 | 0.0000001125 | base | actual | 25 | filled | 2026-10-03 03:10:00 | 109 | 16140 | W4 | 850 | — | ok | ok | n/a | ok (exit 109) | ok | 25 bps vs 25 | n/a |
| 102 | 2026-10-02 22:41:00 | initial_grid | buy | 218290000000 | 0.000045 | 0.000045 | 218290000000 | 0.0000001125 | base | actual | 25 | filled | 2026-10-04 08:00:00 | 111 | 119940 | poller | — | — | ok | ok | n/a | ok (exit 111) | ok | 25 bps vs 25 | n/a |
| 103 | 2026-10-02 22:41:00 | initial_grid | sell | 228380000000 | 0.000044 | 0.000044 | 228380000000 | 25121.8 | quote | actual | 25 | filled | 2026-10-03 14:00:00 | 110 | 55140 | poller | — | — | ok | ok | n/a | ok (exit 110) | ok | 25 bps vs 25 | n/a |
| 104* | 2026-10-02 22:41:00 | initial_grid | sell | 231800000000 | 0.00004504 | 0 | — | — | — | — | — | cancelled | — | — | — | — | — | — | ok | off-step (6 dp) | n/a | n/a (no fill) | n/a | n/a | n/a |
| 105 | 2026-10-05 10:00:00 | rebalance | buy | 216700000000 | 0.000046 | 0.000046 | 216700000000 | 0.000000115 | base | actual | 25 | filled | 2026-10-05 18:00:00 | 112 | 28800 | W4 | 1200 | — | ok | ok | n/a | DUPLICATE: exits 112,116 | ok | 25 bps vs 25 | n/a |
| 106 | 2026-10-05 10:00:00 | rebalance | buy | 213450000000 | 0.000047 | 0.000047 | 213450000000 | 0.0000001504 | base | actual | 32 | filled | 2026-10-06 12:00:00 | 113 | 93600 | poller | — | — | ok | ok | n/a | ok (exit 113) | ok | 32 bps vs 25 DRIFT | n/a |
| 107 | 2026-10-05 10:00:00 | rebalance | sell | 223300000000 | 0.000045 | 0.000045 | 223300000000 | 25121.25 | quote | actual | 25 | filled | 2026-10-07 09:00:00 | 114 | 169200 | poller | — | — | ok | ok | n/a | ok (exit 114) | ok | 25 bps vs 25 | n/a |
| 108 | 2026-10-05 10:00:00 | rebalance | sell | 226650000000 | 0.000044 | 0 | — | — | — | — | — | placed | — | — | — | — | — | — | ok | ok | n/a | n/a (no fill) | n/a | n/a | n/a |
| 109 | 2026-10-03 03:10:02 | cycle_exit | sell | 224944300000 | 0.000044 | 0.000044 | 224944300000 | 24743.873 | quote | actual | 25 | filled | 2026-10-03 09:30:00 | 101 | 22798 | poller | — | 2 | ok | ok | ok | n/a (exit) | ok | 25 bps vs 25 | ok |
| 110 | 2026-10-03 14:00:01 | cycle_exit | buy | 224954300000 | 0.000045 | 0.000045 | 224954300000 | 0.0000001125 | base | actual | 25 | filled | 2026-10-04 02:00:00 | 103 | 43199 | poller | — | 1 | ok | ok | ok | n/a (exit) | ok | 25 bps vs 25 | ok |
| 111 | 2026-10-04 08:00:03 | cycle_exit | sell | 221564350000 | 0.000046 | 0.000046 | 221564350000 | 25479.90025 | quote | actual | 25 | filled | 2026-10-04 20:00:00 | 102 | 43197 | poller | — | 3 | ok | ok | ok | n/a (exit) | ok | 25 bps vs 25 | ok |
| 112 | 2026-10-05 18:00:02 | cycle_exit | sell | 219950500000 | 0.000046 | 0.000046 | 219950500000 | 25294.3075 | quote | actual | 25 | filled | 2026-10-06 04:00:00 | 105 | 35998 | poller | — | 2 | ok | ok | ok | n/a (exit) | ok | 25 bps vs 25 | ok |
| 113 | 2026-10-06 12:00:04 | cycle_exit | sell | 216651750000 | 0.000047 | 0.000047 | 216651750000 | 25456.580625 | quote | actual | 25 | filled | 2026-10-07 01:00:00 | 106 | 46796 | W4 | 400 | 4 | ok | ok | ok | n/a (exit) | ok | 25 bps vs 25 | ok |
| 114 | 2026-10-07 09:00:02 | cycle_exit | buy | 219950500000 | 0.000046 | 0.000046 | 219950500000 | 0.000000115 | base | actual | 25 | filled | 2026-10-08 03:00:00 | 107 | 64798 | poller | — | 2 | ok | ok | ok | n/a (exit) | ok | 25 bps vs 25 | ok |
| 116 | 2026-10-05 18:00:05 | cycle_exit | sell | 219950500000 | 0.000046 | 0 | — | — | — | — | — | placed | — | 105 | — | — | — | 5 | ok | ok | ok | n/a (exit) | n/a | n/a | ok |

`*` = row synced by grid:sync-precision. Exchange ids, client order ids and full check texts: orders.csv.

**Anomalies:**
- [critical] DUPLICATE_EXIT: Fill 105 has 2 live exits (112, 116) — double inventory exposure
- [warning] FEE_RATE_DRIFT: Row 106 (buy) effective fee 32 bps vs configured 25 bps (drift 7 > 5)
- [info] AMOUNT_OFF_STEP: Row 104 amount 0.00004504 has more than 6 decimals (historic: row is cancelled; the exchange truncates)

## 3. Exchange reconciliation

**Method.** For every row with a real nobitex id: NobitexService::getOrdersStatus([id]) one at a time (≥ 300 ms apart); status, amount, filled, price, fee and averagePrice compared with the DB. Rows synced by grid:sync-precision (bot 48: 275–279) had amount/price rewritten to the exchange values; any residual amount/price diff on them is historic and listed as info, not hidden.

Checked 15 order(s); unavailable 0; mismatching fields 2.

| row | nobitex id | field | DB | exchange | severity | note |
|---|---|---|---|---|---|---|
| 104 | 9000104 | amount | 0.00004504 | 0.000045 | info | (row synced by grid:sync-precision — historic diff expected) |
| 108 | 9000108 | price | 226650000000 | 226650000010 | warning |  |

**Anomalies:**
- [warning] EXCHANGE_PRICE_MISMATCH: Row 108 price: DB 226650000000 vs exchange 226650000010
- [info] EXCHANGE_AMOUNT_MISMATCH: Row 104 amount: DB 0.00004504 vs exchange 0.000045 (row synced by grid:sync-precision — historic diff expected)

## 4. Cycles (completed_trades), recomputed independently

**Method.** From the two legs only: qty = filled_amount (else amount), price = avg_fill_price (else limit), fee = stored per-order fee (else an estimate at the configured rate). gross(cash) = sell notional − buy notional; gross(price) = (sell px − buy px) × sell qty; fee(rial) = quote fees + base fees × buy px; base_residual = buy qty − sell qty − base fees; net = gross(cash) − quote fees + base_residual × buy px (the BTC left behind valued at its purchase price — same accounting as CompletedTrade). Flags: |Δnet| or |Δfee| > 1 rial, |Δresidual| > 1e-10 BTC. Duration = earlier leg fill → later leg fill.

| trade | buy/sell | first | buy qty @ px | sell qty @ px | gross cash | gross price | fee (rial) | net | stored net | residual BTC | duration | diff |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 101/109 | buy | 0.000045 @ 221620000000 | 0.000044 @ 224944300000 | -75351 | 146269 | 49676 | 96593 | 96593 | 0.0000008875 | 6h20m | ok |
| 2 | 110/103 | sell | 0.000045 @ 224954300000 | 0.000044 @ 228380000000 | -74224 | 150731 | 50429 | 100302 | 100302 | 0.0000008875 | 12h00m | ok |
| 3 | 102/111 | buy | 0.000045 @ 218290000000 | 0.000046 @ 221564350000 | 368910 | 150620 | 50038 | 100583 | 100583 | -0.0000011125 | 12h00m | ok |
| 4 | 105/112 | buy | 0.000046 @ 216700000000 | 0.000046 @ 219950500000 | 149523 | 149523 | 50215 | 99308 | 99308 | -0.000000115 | 10h00m | ok |
| 5 | 106/113 | buy | 0.000047 @ 213450000000 | 0.000047 @ 216651750000 | 150482 | 150482 | 57559 | 92923 | 92923 | -0.0000001504 | 13h00m | ok |
| 6 | 114/107 | sell | 0.000046 @ 219950500000 | 0.000045 @ 223300000000 | -69223 | 150728 | 50416 | 100312 | 100312 | 0.000000885 | 18h00m | ok |

Totals: **6 cycles**, net **590020** rial (stored 590020), fees 308333, gross(cash) 450118, gross(price) 898353, Σ base residual 0.0000012821 BTC. Avg net 98337, median 99805. Duration avg 11h53m, median 12h00m. Avg net per cycle per 1M rial deployed (buy notional): 9828.8 rial. First leg: buy=4, sell=2.

**Anomalies:**
- [info] CYCLE_RESIDUAL_GE_STEP: completed_trade 3: base residual -0.0000011125 ≥ one qty step

## 5. Base-dust ledger

**Method.** Replayed from 0 in time order through every dust move the code makes: (1) an exit SELL sized from a buy fill moves dust by credited − exit amount (credited = filled − base fee; exit amount from the EXIT_SIZED log when present, else the row); (2) an exit BUY fill moves it by (filled − base fee) − (parent sold + parent base fee); (3) a cancelled partial absorbed into dust moves it by its net base change. Each recomputed step is compared with the row's stored exit_dust_delta, the EXIT_SIZED dust_before/after the bot logged is compared with the replay at that point, and the end value with bot_configs.base_dust.

| at | row | kind | delta (recomputed) | stored | dust before → after | EXIT_SIZED log before → after |
|---|---|---|---|---|---|---|
| 2026-10-03 03:10:02 | 109 | exit_sell_sized | 0.0000008875 | 0.0000008875 | 0 → 0.0000008875 | 0 → 0.0000008875 |
| 2026-10-04 02:00:00 | 110 | exit_buy_settled | 0.0000008875 | 0.0000008875 | 0.0000008875 → 0.000001775 | — |
| 2026-10-04 08:00:03 | 111 | exit_sell_sized | -0.0000011125 | -0.0000011125 | 0.000001775 → 0.0000006625 | 0.000001775 → 0.0000006625 |
| 2026-10-05 18:00:02 | 112 | exit_sell_sized | -0.000000115 | -0.000000115 | 0.0000006625 → 0.0000005475 | 0.0000006625 → 0.0000005475 |
| 2026-10-05 18:00:05 | 116 | exit_sell_sized | -0.000000115 | -0.000000115 | 0.0000005475 → 0.0000004325 | 0.0000005475 → 0.0000004325 |
| 2026-10-06 12:00:04 | 113 | exit_sell_sized | -0.0000001504 | -0.0000001504 | 0.0000004325 → 0.0000002821 | 0.0000004325 → 0.0000002821 |
| 2026-10-08 03:00:00 | 114 | exit_buy_settled | 0.000000885 | 0.000000885 | 0.0000002821 → 0.0000011671 | — |

Replayed **0.0000011671** BTC; Σ stored exit_dust_delta 0.0000011671; bot_configs.base_dust **0.0000011671**; Δ 0 (one qty step = 0.000001).

_Anomalies: none._

## 6. Balance reconciliation

**Method.** Σ over every fill (filled_amount > 0) with fill time ≥ window start: buy → rls −= qty×avg, btc += qty; sell → rls += qty×avg, btc −= qty; then the fee leaves in its own currency (stored fee, else an estimate at the configured rate).

Fills counted 12 (with estimated fee: 0). Σ flows: rls 298900.338625, btc 0.0000012821.

| | rls | btc |
|---|---|---|
| start (--start-*, at 2026-10-02 22:41:00) | 126387058 | 0.000299721 |
| expected now = start + flows | 126685958.338625 | 0.0003010031 |
| wallet total (getBalances) | 126685958.338625 | 0.0003010031 |
| wallet available | 126685958.338625 | 0.0002110031 |
| wallet locked | 0 | 0.00009 |
| expected locked, this bot's open orders | 0 | 0.00009 |
| expected locked, all bots' open orders | 0 | 0.00009 |
| wallet locked − open orders (all bots) | 0 | 0 |
| **residual = wallet total − expected** | **0** | **0** |

Possible explanations for a residual: other bots trading on the same account (their fills since the snapshot are not in this ledger); manual trades / deposits / withdrawals since the snapshot; start snapshot taken as activeBalance (excludes funds blocked at that moment) instead of total; fees estimated where the exchange fee was not captured.

Start snapshot total vs activeBalance (ESTIMATE): consistent with TOTAL (or activeBalance with nothing blocked at the snapshot): the ledger explains the wallet to within 1000 rial / 1e-6 BTC. Not determinable from data alone: the snapshot's blocked balance at that moment was not recorded.

_Anomalies: none._

## 7. Open position & unrealized P&L

**Method.** Open exit SELLs = long cycles: BTC held = exit remaining, cost basis = parent buy notional pro-rated to the held BTC; value at the current price, unrealized after an estimated exit fee. Open exit BUYs = short cycles: proceeds of the parent sell after fee vs the cost of buying back the inventory-restoring amount now. Grid sells that are not exits are BTC inventory on the book, not cycles. Net P&L = realized (§4 recomputed) + unrealized.

Current price: **224055554700** (last candle close (1h)).

- long cycle: exit 116 (parent 105): 0.000046 BTC bought @ 216700000000, cost 9993183, exit @ 219950500000, value now 10306556, unrealized 287606

| item | value |
|---|---|
| BTC held by open long cycles | 0.000046 |
| cost basis of that BTC | 9993183 |
| BTC owed by open short cycles | 0 |
| grid-sell BTC on the book (rows 108) | 0.000044 |
| realized net | 590020 |
| unrealized | 287606 |
| **net P&L** | **877626** |

Buy-and-hold benchmark (ESTIMATE (candle prices)): price 224055554700 → 224055554700. Holding the active budget fully in BTC would have made 0 rial.

| equity (rial, BTC at price_now) | value |
|---|---|
| start (BTC at price_start) | 193541213 |
| hold start balances | 193541213 |
| bot, by ledger (start + flows) | 194127375 |
| **bot − hold (ledger)** | **586162** |
| wallet now | 194127375 |
| wallet − hold | 586162 |

## 8. Infrastructure health

**Method.** Every `*.log` and rotated `*.log.gz` under the log dir (gzopen), lines inside the window, `testing.*` ignored. Counts are over ALL log lines in the window (every bot and process), testing.* lines ignored; W4 latency is over this bot's WS_EVENT_ACTED lines only.

| day | ERROR | CRITICAL | WARNING | WS_PRIVATE_RECONNECT | WS_PRIVATE_FLAPPING | WS_PRIVATE_CONNECT_FAILED | WS_FEED_* | WS_EVENT_API_LAG_* | WS_EVENT_JOB_FAILED | ORDER_PRICE_ROUNDED | PRECISION_* | FEE_RATE_DRIFT | FEE_CURRENCY_UNEXPECTED | EXIT_BLOCKED | EXIT_REJECTED | QUEUE_DEPTH_* | KILL_SWITCH_* | RECONCILE_STUCK/UNRESOLVED |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 2026-10-04 | 0 | 0 | 3 | 2 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| 2026-10-06 | 1 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| **total** | 1 | 0 | 4 | 2 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 |

W4 latency_ms (WS_EVENT_ACTED): n=3 min=400 median=850 p95=1200 max=1200. Outcomes processed=3.

Top ERROR/CRITICAL events: ERROR CheckTradesJob: ×1

**Anomalies:**
- [warning] LOG_WS_PRIVATE_FLAPPING: 1 WS_PRIVATE_FLAPPING log line(s) in the window

## 9. Market context & missed opportunity — ESTIMATE

**Method.** 1h candles from GET /market/udf/history (TOMAN ×10 → rial), window-chunked. Touches = runs of consecutive candles whose [low, high] contains the level; crosses = close-to-close side changes. Replay: see limits below.

Candles 158 (expected ≈158, 2026-10-02 23:00 → 2026-10-09 12:00 UTC-epoch based). Open 224055554700 → close 224055554700 (0%); high 229699998600, low 215300000000, range 6.688%; σ(return) 0.3502% per candle; mean |return| 0.349%.

| level | rows | touch episodes | close crosses |
|---|---|---|---|
| 213450000000 | 106 | 0 | 0 |
| 216651750000 | 113 | 8 | 8 |
| 216700000000 | 105 | 8 | 8 |
| 218290000000 | 102 | 8 | 8 |
| 219950500000 | 112,114,116 | 8 | 8 |
| 221564350000 | 111 | 8 | 8 |
| 221620000000 | 101 | 8 | 8 |
| 223300000000 | 107 | 8 | 8 |
| 224944300000 | 109 | 10 | 10 |
| 224954300000 | 110 | 10 | 10 |
| 226650000000 | 108 | 10 | 10 |
| 228380000000 | 103 | 10 | 10 |
| 231800000000 | 104 | 0 | 0 |

| policy (same candles, same starting orders) | cycles | fills | open exits at end | est. net (rial) |
|---|---|---|---|---|
| actual (completed_trades) | 6 | — | — | — |
| replay: bot rule (exit fills do not re-arm) | 6 | 12 | 0 | 598351 |
| replay: classic re-arming grid | 36 | 42 | 6 | 3587627 |

Limits:
- Candle granularity (1h): the intra-candle path is assumed O→L→H→C (up candle) or O→H→L→C (down candle); multiple swings inside one candle are invisible, so both counts are LOWER bounds for a choppy market.
- Fill at touch: a limit fills as soon as the price reaches it — no queue position, no partial fills, no exchange latency; real fills need the price to trade THROUGH the level, so this is optimistic per touch.
- Fees: cycle net uses FeeModel::cycleEstimate at the configured rates on the parent order notional; no slippage.
- Each build is replayed from the first candle that opens after it; unfilled grid orders are dropped at the next build, spawned exits carry over (the bot never cancels exits).
- The bot-rule replay is a calibration check: if it is far from the actual count, distrust the classic number by the same factor.

_Anomalies: none._

## 10. Anomalies (consolidated)

| # | severity | code | finding | rows | § |
|---|---|---|---|---|---|
| 1 | critical | DUPLICATE_EXIT | Fill 105 has 2 live exits (112, 116) — double inventory exposure | 105,112,116 | 2 |
| 2 | warning | EXCHANGE_PRICE_MISMATCH | Row 108 price: DB 226650000000 vs exchange 226650000010 | 108 | 3 |
| 3 | warning | FEE_RATE_DRIFT | Row 106 (buy) effective fee 32 bps vs configured 25 bps (drift 7 > 5) | 106 | 2 |
| 4 | warning | LOG_WS_PRIVATE_FLAPPING | 1 WS_PRIVATE_FLAPPING log line(s) in the window |  | 8 |
| 5 | info | AMOUNT_OFF_STEP | Row 104 amount 0.00004504 has more than 6 decimals (historic: row is cancelled; the exchange truncates) | 104 | 2 |
| 6 | info | CYCLE_RESIDUAL_GE_STEP | completed_trade 3: base residual -0.0000011125 ≥ one qty step | 102,111 | 4 |
| 7 | info | EXCHANGE_AMOUNT_MISMATCH | Row 104 amount: DB 0.00004504 vs exchange 0.000045 (row synced by grid:sync-precision — historic diff expected) | 104 | 3 |

## 11. Opinion

_OPINION — generated heuristics from the facts above; a human/AI review of this report is the real analysis._

1. **risk** — Resolve the 1 CRITICAL finding(s) first (DUPLICATE_EXIT). Nothing else in this list matters until each is explained or fixed.
2. **worked** — Unit economics: spacing 1.5% vs break-even 0.5019% (buy-first) / 0.4994% (sell-first); 6 booked cycle(s), realized net 590020 rial, median 99805 rial per cycle.
3. **change** — Missed opportunity (ESTIMATE): on the same candles a re-arming grid closes 36 cycle(s) vs 6 by the bot's rule (actual: 6). Re-arming exits (each exit fill places its opposite) is the largest lever on cycle count; it also keeps inventory exposure on both sides, so ship it behind a flag and on a paper run first.
4. **risk** — Private WS: 2 reconnect(s), 1 flapping alert(s). The minute poller is the safety net; check exit delays in §2 for fills the WS missed.
5. **worked** — W4 acted 3 time(s); latency median 850 ms, max 1200 ms.
6. **worked** — Balance residual: 0 rial / 0 BTC vs start + fills. consistent with TOTAL (or activeBalance with nothing blocked at the snapshot): the ledger explains the wallet to within 1000 rial / 1e-6 BTC.
7. **scaling** — Scaling: NOT yet supported by the data — 6 cycle(s) and 1 critical finding(s). Evidence needed: ≥30 booked cycles over ≥2 weeks including at least one ≥5% directional move, zero critical findings, an exchange reconciliation with no unexplained diffs, a balance residual explained to the rial, and the dust ledger matching.
