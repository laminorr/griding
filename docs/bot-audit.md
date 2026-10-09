# bot:audit — read-only forensic audit of one bot

```
php artisan bot:audit {botId} [--from=] [--to=] [--start-rls= --start-btc=] [--no-exchange]
                              [--candles=1h] [--synced=275-279] [--log-dir=] [--rate-ms=300]
```

Host (bot 48):

```
ea-php83 artisan bot:audit 48 --start-rls=126387058 --start-btc=0.000299721
```

Writes `storage/app/audits/bot{ID}-{YYYYmmdd-HHMM}/`:

| file | content |
|---|---|
| `report.md` | the human report, sections 1–11 (below) |
| `data.json` | everything machine-readable (exact decimal strings) |
| `orders.csv` | one line per grid_orders row with every check |
| `cycles.csv` | one line per completed_trade, recomputed vs stored |
| `events.csv` | timeline: order created/filled, cycles booked, bot log lines, WS reconnects, activity log, exchange_ws_events |

and prints the path plus a 15-line summary.

## Guarantees

- **No DB writes.** Only reads of bot_configs, grid_orders, completed_trades,
  bot_activity_logs, exchange_ws_events. Helpers that write the cache
  (`MarketPrecision::priceTick`, `FeeModel::classifyActualFee`,
  `CandleService::getCandles`) are deliberately not called; precision comes
  from the cached precision maps (read only) or config.
- **No order placement / cancel / modify.** Exchange calls are
  `getOrdersStatus([id])` (one per order), `getBalances()` and the public OHLC
  history, at least `--rate-ms` (minimum 300) apart. `--no-exchange` makes zero
  HTTP calls (sections 3, 6-residual, 7-benchmark and 9 are then skipped).
- **bcmath only** (`App\Support\Money`); no float in money math.
- **FACT / ESTIMATE / OPINION** are kept apart: §9 and the §6 start-snapshot
  verdict are labelled ESTIMATE, §11 is the only opinion.

## Sections

1. Bot & timeline: config, builds (initial grid / rebalances) with the budget logged for each, kill-switch / stop-loss events.
2. Order ledger: every row, checks (a) tick (b) qty step (c) exit price (d) one exit per fill (e) fee currency (f) effective fee bps (g) sell above buy; W4 vs poller path, exit delay.
3. Exchange reconciliation: status/amount/filled/price/fee/averagePrice per order. Rows repaired by `grid:sync-precision` (from `PRECISION_ROW_SYNCED` logs and `--synced`) are labelled historic.
4. Cycles recomputed independently from the two legs, compared to the stored booking (> 1 rial / > 1e-10 BTC flagged).
5. Base-dust ledger replayed from 0 through every dust move; compared per row, against the bot's own `EXIT_SIZED` lines, and against `bot_configs.base_dust`.
6. Balance reconciliation: start + Σ fill cash flows vs the wallet; locked balance vs open orders.
7. Open position, unrealized P&L, buy-and-hold benchmark.
8. Infrastructure health from `storage/logs/*.log` and `*.log.gz` (gzopen), per day; `testing.*` lines ignored; W4 latency.
9. Market context and a candle replay of the bot's rule vs a classic re-arming grid (ESTIMATE, limits stated in the report).
10. Consolidated anomalies (critical / warning / info) with row ids.
11. Opinion.

Times are shown as stored (app timezone, `config('app.timezone')`).
