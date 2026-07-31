# Grid Trading Bot — Factual Inventory

> Reference inventory for the Laravel + Filament v3 grid-trading bot (Nobitex
> exchange, pair BTCIRT). PHP 8.3, ext-bcmath, MySQL (`savesir_nobsaves`),
> cache driver `database`. Every non-obvious fact is cited `file:line`.
> Items marked **UNCERTAIN** were not fully verified.

Conventions: "IRT" is the display currency; the private order endpoints use
`rls` (see §7). "bps" = basis points (`35 bps = 0.35%`). All money math runs
through `App\Support\Money` (bcmath) unless noted.

---

## 1. High-level architecture

### 1.1 Services (`app/Services`)

| Class | Purpose (one line) |
|---|---|
| `TradingEngineService` | Thin wrapper: **grid initialization only** (`initializeGrid`); ongoing lifecycle is in the jobs. `TradingEngineService.php:29` |
| `GridCalculatorService` | Level generation (log/arith/geo), order-size, market-analysis, profit/risk estimation. `GridCalculatorService.php:26` |
| `GridPlanner` | Produces a grid **plan** (levels, tick-aligned prices, per-level qty/notional, fee estimate). `GridPlanner.php:10` |
| `GridOrderSync` | Diffs a plan against existing orders → `to_place` / `to_cancel` / `keep`. `GridOrderSync.php:8` |
| `GridOrderExecutor` | Applies a diff to the exchange; creates `GridOrder` rows; dedup + deterministic `client_order_id`. `GridOrderExecutor.php:26` |
| `KillSwitchService` | Stop-loss / max-drawdown circuit breaker; sets `is_active=false`. `KillSwitchService.php:30` |
| `NobitexService` | REST client for Nobitex v2 (orders, wallets, stats, orderbook, reconciliation reads). `NobitexService.php:39` |
| `NobitexAuthService` | Login / 30-day token / auto-refresh helper. `NobitexAuthService.php` |
| `NobitexWebSocketService` | Centrifugo WS consumer; writes price/orderbook cache keys. `NobitexWebSocketService.php:20` |
| `MarketDataLayer` | Unified market-data source: Cache → WS snapshot → REST. Implements `MarketData`. `MarketDataLayer.php:24` |
| `WebSocketHealthService` | WS health/heartbeat status (for the WS health widget). `WebSocketHealthService.php` |
| `BotActivityLogger` | Structured per-bot activity log writer (`bot_activity_logs`). `BotActivityLogger.php` |
| `SubmissionReconciler` | Resolves `submission_unknown` / stale `pending` rows via read-only Nobitex lookups. `SubmissionReconciler.php:62` |
| `RateLimiting/CacheRateLimiter` | Cache-backed fixed-window enforcing rate limiter (used only when enforce=true). `RateLimiting/CacheRateLimiter.php` |

### 1.2 Jobs (`app/Jobs`) & scheduling

Schedules are defined in **`routes/console.php`** (the `Console\Kernel::schedule()`
is intentionally empty — `Kernel.php:25`). All schedules are gated by
`config('trading.enable_scheduler')` (default `true`). `routes/console.php:15`

| Job | Cadence (actual code) | Dispatched by | Notes |
|---|---|---|---|
| `CheckTradesJob` | `everyFiveMinutes()` `routes/console.php:18` | scheduler | `withoutOverlapping`; `$tries=3`, `$timeout=120`, `$backoff=[2,4,8]` `CheckTradesJob.php:42-53` |
| `AdjustGridJob` | `everyTenMinutes()` `routes/console.php:25` | scheduler | `withoutOverlapping(20)`, `onOneServer()` |
| `ReconcileSubmissionsJob` | `everyFiveMinutes()` `routes/console.php:36` | scheduler | `withoutOverlapping`; `$tries=1`, `$timeout=120` `ReconcileSubmissionsJob.php:33-35` |
| `ReadMarketStatsJob` | `everyMinute()` per symbol (BTCIRT/ETHIRT/USDTIRT) `routes/console.php:47-53` | scheduler | `withoutOverlapping(2)`; logs price/spread to `trading` channel |
| (`queue:prune-batches --hours=48`) | `dailyAt('03:20')` `routes/console.php:41` | scheduler | command, not a Job |

> **UNCERTAIN / discrepancy:** `config('trading.scheduler.interval_check_trades')`
> (=60) and `interval_adjust_grid` (=600) exist but the scheduler uses the
> literal `everyFiveMinutes()`/`everyTenMinutes()` helpers, so
> `interval_check_trades` (60s) is **not** what actually runs — check-trades
> runs every 5 minutes. `config/trading.php:188-193`.

### 1.3 Models (`app/Models`) → table

| Model | Table |
|---|---|
| `BotConfig` | `bot_configs` |
| `GridOrder` | `grid_orders` `GridOrder.php:11` |
| `CompletedTrade` | `completed_trades` (default) |
| `GridRun` | `grid_runs` `GridRun.php:16` |
| `GridRunOrder` | `grid_run_orders` `GridRunOrder.php:21` |
| `GridEvent` | `grid_events` `GridEvent.php:10` |
| `BotActivityLog` | `bot_activity_logs` (default) |
| `User` | `users` (Authenticatable, implements `FilamentUser`) `User.php:20` |

### 1.4 Observers

- **`GridOrderObserver`** — observes **`GridOrder`** (`saved`, `deleted`).
  Registered in `AppServiceProvider::boot()` `AppServiceProvider.php:45`. It is
  the **only** observer in the app (`GridOrderObserver.php:44`). Recomputes
  `bot_configs.open_cycles_count` and `capital_locked_irt` (see §4).

### 1.5 Filament resources & pages

- **`BotConfigResource`** — manages `BotConfig` (the grid bots). Nav label
  "ربات‌های گرید", group "معاملات". Row `start` action calls
  `TradingEngineService::initializeGrid()` `BotConfigResource.php:447-461`; `stop`
  action at `:489`. `BotConfigResource.php:41`
- **`GridRunResource`** — manages `GridRun` (monitoring of grid runs), group
  "Monitoring". Relation managers: `EventsRelationManager`,
  `OrdersRelationManager`; widget `RunStats`. `GridRunResource.php:20`
- Pages: `BotIntelDashboard` ("Bot Intelligence"), `BotMonitoring`
  ("Bot Monitoring"), `ConnectionTest` ("آزمایش اتصال"), `GridCalculator`
  ("Grid Calculator نسل آینده"), `Notes` ("گفتمان").
- Widgets: `BotStatusWidget`, `PerformanceChartWidget`, `WebSocketHealthWidget`.
- Livewire: `GridCalculatorAdvanced`, `GridCalculatorChart`, `GridLevelsChart`,
  `GridLevelsTable`, `GridStatsCards`.

### 1.6 DTOs (`app/DTOs`) and Enums (`app/Enums`)

**DTOs:** `ApiOkDto`, `BalanceDto`, `CreateOrderDto`, `CreateOrderResponse`,
`OrderBookDto`, `OrderStatusDto`, `WalletsDto`.
`CreateOrderDto` (`CreateOrderDto.php:15`) carries `side, execution,
srcCurrency, dstCurrency, amountBase, priceIRT, clientRef`; `toApiPayload()`
maps `irt→rls`, truncates amount **down** to `qty_decimals`, and emits
`clientOrderId` from `clientRef` (`CreateOrderDto.php:69,86-90`).
`OrderStatusDto` (`OrderStatusDto.php:14`) has **no** `clientOrderId` field.

**Enums (with cases):**
- `GridOrderStatus: string` — `PENDING, ACTIVE, PARTIALLY_FILLED, FILLED,
  CANCELED, ERROR` `GridOrderStatus.php:11-23`. `fromString()` maps Nobitex
  `DONE→FILLED`, `INACTIVE→CANCELED`, etc. Nobitex never emits
  `PARTIALLY_FILLED` (a partial is `ACTIVE` + `matchedAmount>0`).
- `OrderSide: string` — `BUY, SELL` `OrderSide.php:13-14`; `opposite()` at `:61`.
- `ExecutionType: string` — `MARKET, LIMIT` `ExecutionType.php:13-14`.

**Support (`app/Support`):** `Money`, `Math`, `OrderRegistry`, `GridRunRecorder`.

---

## 2. The grid lifecycle — start → orders on the exchange

Entry point: **`TradingEngineService::initializeGrid(BotConfig, array $options)`**
`TradingEngineService.php:60`. Called from the three Filament pages
(CreateBotConfig, ListBotConfigs, BotConfigResource start action).

### 2.1 `initializeGrid` — steps in order

0. **Kill-switch gate first** — `killSwitch->checkAndTrigger($bot)`; if
   `triggered`, abort with `error_code=KILL_SWITCH_ACTIVE` (also covers
   re-running an already-killed bot). `:70-85`
1. **Preflight** `performPreflightChecks()` — **simulation bots skip** API-key
   check + `healthCheck()` (`:247-250`); live bots require
   `config('services.nobitex.api_key')` and an `ok` health check. `:88`
2. **Market analysis** `analyzeMarketForGrid()` (`quickMarketAnalysis`); if not
   suitable and `options['force_start']` is false → throw; else log a loud
   `FORCE_START_OVERRIDE`. `:94-101`, `:279`
3. **Center price** `calculateOptimalCenterPrice()` (see §2.2). `:104`
4. **Levels** `gridCalculator->calculateGridLevels(centerPrice, grid_spacing,
   grid_levels, mode)` (mode via `resolveBotMode`, default `both`). `:110-115`,
   `:375`
5. **Order size** `calculateOrderSize(total_capital, active_capital_percent,
   grid_levels, symbol)`; `active_capital_percent` defaults to 100 and must be
   `0<x≤100`. `:123-137`
5b. **Quote-balance check** `verifySufficientQuoteBalance()` — **skipped for
   simulation** (`:402`) and for sell-only mode (`:410`). Sums buy-level notional
   + a `fee_bps/10000` buffer (`fee_bps ?? config('trading.fee_bps',35)`) and
   compares to available quote balance. `:400-464`
6. **Cleanup** `cleanupExistingOrders()` — cancels `placed`/`pending` orders;
   **simulation logs only** (`:530`), live calls `cancelOrder`; a cancel failure
   **propagates** (no swallow) so a new grid never stacks on stale orders.
   `:514-565`
7. **Place** `placeGridOrders()` → GridPlanner → GridOrderSync → GridOrderExecutor
   (see §2.4). `:153`
8. **Health** `evaluateInitializationHealth()` → `init_status ∈
   {running, partially_initialized, failed}`. Bar: ≥1 success on every
   *planned* side AND success ratio ≥ 0.8 (bcmath). `:319-365`

On success, updates `bot_configs`: `center_price`, **`grid_center_price`**
(stable kill-switch anchor captured here), `is_active`, `init_status`,
`started_at`, `last_rebalance_at`, `stop_reason`. `:165-177`.
No DB transaction wraps the exchange calls — each `GridOrder` row is persisted
immediately after its own exchange call (avoids orphaned exchange orders). `:209-217`

### 2.2 Center-price selection (weighted average)

`calculateOptimalCenterPrice()` `:495-509`:
- If `options['center_price']` set → use it.
- Else `currentPrice = nobitex->getCurrentPrice(symbol)`.
- If bot has no prior `center_price` → use `currentPrice`.
- Else **weighted average: `currentPrice*0.7 + bot.center_price*0.3`**. `:508`

### 2.3 `GridPlanner::plan` `GridPlanner.php:34`

Config pulled: `tick = config("trading.ticks.$symbol") ?? 10`; `minNotional =
config('trading.min_order_value_irt')` (fallback 3,000,000); `feeBps =
config('trading.exchange.fee_bps') ?? 35`; `qtyDecimals =
config("trading.exchange.precision.$symbol.qty_decimals") ?? 6`. `:49-59`

- **Modes:** validated `both|buy|sell`. `both` requires an **even** `levels`
  (odd throws) `:73-75`. `perSide = both ? levels/2 : levels` `:84`.
- **Layout:** buys **below** mid (`factor = pow(1-step, i)`), sells **above**
  (`factor = pow(1+step, i)`). Level price = `mid × factor` computed on strings
  via `Money::mul`; `pow()` is the only native-float step (geometric spacing).
  `:90-113`.
- **Tick alignment (direction differs per side):** buys round **down**
  (`roundToTick(down:true)` = floor), sells round **up** (ceil to next tick).
  `:101,111`; `roundToTick` at `:227`.
- **Collapse:** duplicate `side:price` items on the same tick are collapsed;
  counted in `collapsed_levels`. `:116-123`
- **Sort:** buys ascending, sells descending, buys first. `:126-133`
- **Per-level qty/notional (priority order):**
  1. `presetSellQty` for sell levels if balance-aware sizing engaged (§2.5).
  2. else `fixedQty` if provided.
  3. else budget-derived: `notional = budgetIrt / count` (scale 0 = floor),
     `qty = notional / max(price,1)` to `qtyDecimals`. `:162-193`
- **Min-order handling:** `below_min = notional>0 && notional<minNotional`
  (reported only; the actual **skip** happens in `GridOrderSync`). `:186-187`
- **Fee estimate:** `ceil(sumNotional*feeBps / 10000)` on strings. `:196-198`

### 2.4 `presetBaseQty` — balance-aware sizing (Phase 11 Step 5, initial placement only)

`TradingEngineService::computePresetBaseQty()` `:805-887`. Returns `null`
(naive plan) when: base balance is null (all simulation bots), mode is `buy`,
mid/budget non-positive, holdings value < half a sell side's notional, or
holdings value > whole budget. Otherwise returns the **full available base**;
`GridPlanner` splits it evenly across sell levels
(`perRaw = presetBaseQty / sellCount`) and uses it as each sell's qty
`GridPlanner.php:146-160`. Only the sell side is affected; buys keep
fixedQty/budget sizing. Rebalance (`AdjustGridJob`) does **not** use preset sizing.

### 2.5 Plan → real orders (`GridOrderSync` → `GridOrderExecutor`)

- **Diff** `GridOrderSync::diff(plan, existing, toleranceTicks=1,
  qtyTolerancePct=3.0)` `GridOrderSync.php:21`. Matches within
  `toleranceTicks*tick` on price and `qtyTolerancePct` on qty. Plan items below
  `minIrt` are **skipped** (`skipped_below_min`) rather than placed `:88-92`.
  Unmatched existing orders → `to_cancel`, **except** those protected by
  `role ∈ {cycle_exit, manual}` **or** a non-empty `paired_order_id` `:114-128`.
- **Execute** `GridOrderExecutor::applyForBot(botId, diff, simulation, role)`
  `GridOrderExecutor.php:49`. `role` stamped on every created row
  (`initial_grid` from init, `rebalance` from AdjustGridJob).
  - Cancels first (`to_cancel`), then places (`to_place`).
  - **`clientOrderId` = `GridOrder::buildClientOrderId(botId, SYMBOL, side,
    price)`** → format `grid:{botId}:{SYMBOL}:{side}:{priceIrt}` `GridOrder.php:126-139`.
    Deterministic on the level's stable identity, not a loop index — retries
    can't duplicate.
  - **Dedup guard:** skip if an active row (`pending|placed|filled|
    partially_filled`) with that `client_order_id` exists `:148-161,190-203`.
  - **Simulation:** creates a `GridOrder` directly at `status='placed'` with
    `nobitex_order_id='SIM-'.uniqid()`, no API call `:170-186`.
  - **Live:** persists a `pending` intent row **before** the API call, then
    `createOrder(CreateOrderDto{... clientRef: clientOrderId})`; on success →
    `placed` + real `nobitex_order_id` `:210-255`. On failure: `cancelled` if the
    API was never reached, else **`submission_unknown`** (ambiguous) `:257-275`.
  - `apply()` (`:302`) is a legacy unscoped entry point (no rows, no dedup) —
    production uses `applyForBot`.
- `placeGridOrders` recovers success/fail counts by re-reading rows created
  since `$callStartedAt` (applyForBot is void) `TradingEngineService.php:731-770`.

---

## 3. Trade cycle and profit

### 3.1 A fill spawns its opposite

`CheckTradesJob::handle()` selects active bots (`is_active=true`), then per bot
`processBot()`:
- Poll `placed`/`partially_filled` orders — simulation via
  `checkSimulatedOrders()` (local price match: buy fills when market ≤ price,
  sell when market ≥ price, bcmath compare `CheckTradesJob.php:213-220`); live via
  `checkOrdersStatus()` → `getOrdersStatus()`.
- Then select `status='filled'` AND `paired_order_id IS NULL`, and call
  `createPairOrder()` for each. `CheckTradesJob.php:125-134`

`createPairOrder()` `:671`: **honours the kill switch** — if `!is_active`, logs
`SKIP_PAIR_KILLED` and returns (no new cycle_exit order) `:679-686`. Takes a
per-fill `Cache::lock("pair-order:{id}",10)` `:688`, then `createPairOrderLocked`.

### 3.2 `createPairOrderLocked` — pair amount & price `:705`

- `newType = opposite(filledOrder.type)`.
- Continuation **price**: `spacing = grid_spacing/100`; sell →
  `filled.price*(1+spacing)`, buy → `filled.price*(1-spacing)`, all bcmath, then
  `(int) round((float)$rawPrice)`. `:714-723`
- **Pair amount coupling:** `pairAmount = filledOrder.filled_amount ??
  filledOrder.amount` — the pair is sized by what actually executed. `:734`
- Dedup on `client_order_id` including `submission_unknown` `:744-747`.
- **Transaction boundary (only the linkage):** re-read fill
  `lockForUpdate()`; create the `pending` cycle_exit row with
  `paired_order_id=filledOrder.id`; back-link `filledOrder.paired_order_id=
  newOrder.id`; commit. Exchange placement happens **after** commit, outside any
  transaction. `:769-815`
- Placement: simulation → `SIM-*` id, status `placed`. Live → `placeOrder(...)`;
  on ambiguous failure `submission_unknown` (attempted) or `cancelled` +
  unlink fill (never attempted). `:837-924`
- `role='cycle_exit'` on the new order `:801`.

### 3.3 `CompletedTrade::createFromOrders(buy, sell)` — exact formulas `CompletedTrade.php:332`

All bcmath on decimal strings (`Money::normalize` of price/amount):
- `buyAmount, sellAmount` normalized; **`amount = Money::min(buyAmount,
  sellAmount)`** (unequal legs logged `COMPLETED_TRADE_UNEQUAL_LEG_AMOUNTS`).
  `:365-377`
- `grossProfit = (sellPrice − buyPrice) × amount`. `:380`
- **Fee source:** `feeBps = buyOrder->botConfig?->fee_bps ??
  config('trading.exchange.fee_bps', 35)`. A literal **`fee_bps=0` is honoured**
  (`??` fires only on null). `:394`
- `feeRate = feeBps / 10000`. `:395`
- `buyNotional = buyPrice×amount`; `sellNotional = sellPrice×amount`;
  **`fee = totalFee = feeRate × (buyNotional + sellNotional)`** (both legs).
  `:397-399`
- `netProfit = grossProfit − totalFee`. `:402`
- **`profit` column is stored as `netProfit`** (`:420` `'profit' => $netProfit`);
  `net_profit` also = netProfit `:423`.
- `profit_percentage = buyNotional==0 ? "0" : grossProfit/buyNotional × 100`.
  `:406-408`
- `execution_time_seconds = buyOrder.created_at.diffInSeconds(sellOrder.updated_at)`.
  `:411`
- `grid_level_buy/sell` explicitly `null`; `market_conditions` = btc_price cache
  + trend. `:429-435`

> Note: `CheckTradesJob::recordCompletedTrade()` (`:941`) computes a **native-float**
> profit for logging only; the persisted numbers come from `createFromOrders`.
> `recordCompletedTrade` gross uses `buyOrder.amount` (`:955`) — a log value, not
> the stored one.

### 3.4 Invariants INV-5/6/7 and where enforced

- **INV-5 — profit only when BOTH legs filled:** booked exclusively in
  `createCompletedTradeIfPaired()` which returns early unless `partner->status
  === 'filled'`. `CheckTradesJob.php:596-623`
- **INV-6 — sell > buy regardless of fill order:** buy/sell roles assigned by
  `type` not fill order: `[$buyOrder,$sellOrder] = order.type==='buy' ?
  [order,partner] : [partner,order]`. `CheckTradesJob.php:632-634`; profit math
  in `createFromOrders` uses these roles (§3.3).
- **INV-7 — never double-book:** `CompletedTrade::where(buy_order_id)->
  where(sell_order_id)->exists()` guard. `CheckTradesJob.php:638-645`. Also the
  `paired_order_id` back-link + `whereNull(paired_order_id)` filter prevents
  re-selecting the same fill.

### 3.5 Open vs closed cycle

- **Open cycle:** a `role='cycle_exit'` order at `status='placed'` (the
  continuation waiting to fill). Counted in `open_cycles_count`
  (`GridOrderObserver.php:99-105`).
- **Closed cycle:** both legs `filled` → a `CompletedTrade` row is booked
  (§3.4); the cycle no longer counts as open.

---

## 4. Accounting columns (`GridOrderObserver`)

`recomputeInventoryForBot(BotConfig)` `GridOrderObserver.php:97`, fired on every
`GridOrder` save/delete via `recompute()` (wrapped in try/catch — a recompute
failure never breaks the save; logs `GRID_ORDER_OBSERVER_RECOMPUTE_FAILED` to
the **`trading`** channel `:83-90`).

- **`open_cycles_count`** = count of `role='cycle_exit'` AND `status='placed'`,
  **both sides**. `:101-105` (column: `TINYINT UNSIGNED` nullable, cast
  `integer`; NULL="not yet computed").
- **`capital_locked_irt`** = Σ over **buy-side** open cycles only. A buy-side
  open cycle = a `cycle_exit` **sell** at `placed` with a non-null
  `paired_order_id`; its locked notional = the **filled buy's** `price × amount`
  (bcmath). A `cycle_exit` **buy** waiting to fill locks nothing (IRT is
  available). Stored at scale 0 (`Money::add(..., '0', 0)`). `:107-136`
  (column: `DECIMAL(20,0)` nullable, **not** Eloquent-cast — see §5/§10).

### 4.1 `bot_configs` runtime accounting/state columns

`open_cycles_count`, `capital_locked_irt` (both above), `grid_center_price`
(kill-switch anchor), `center_price` (moving), `init_status`, `is_active`,
`stop_reason`, `stopped_at`/`started_at`, `last_check_at`, `last_rebalance_at`,
`rebalance_count`, `last_run_at`, `last_error_code`, `last_error_message`,
`total_profit`, `win_rate`. (Migrations in §8.)

---

## 5. Money / precision (`app/Support/Money.php`)

Default scale: **`Money::DEFAULT_SCALE = 20`** `Money.php:49`. Stateless, pure,
strings-in/strings-out; every method trims trailing zeros via `trimZeros()`.

| Method (signature) | Description |
|---|---|
| `add(string $a,$b,int $scale=20): string` | `$a+$b` `:63` |
| `sub(string $a,$b,int $scale=20): string` | `$a-$b` `:76` |
| `mul(string $a,$b,int $scale=20): string` | `$a*$b` `:89` |
| `div(string $a,$b,int $scale=20): string` | `$a/$b`; **throws `DivisionByZeroError`** on zero divisor (unlike bare bcdiv) `:106` |
| `compare(string $a,$b,int $scale=20): int` | -1/0/1 `:129` |
| `min(string ...$values): string` | smallest (throws if empty) `:141` |
| `max(string ...$values): string` | largest `:162` |
| `isZero/isPositive/isNegative(string $a,int $scale=20): bool` | sign predicates `:188,199,210` |
| `abs(string $a): string` | strip leading `-` (string-only) `:228` |
| `normalize(mixed $v): string` | coerce int/float/string → bcmath-safe string; **float via `sprintf('%.20F')`** (no scientific notation); rejects null/bool/NAN/INF/non-scalar `:249` |
| `round(string $value,int $scale): string` | round half-up (float-casts via `round()` internally) `:282` |
| `alignToTick(string $value,$tickSize,string $mode='floor'): string` | snap to tick (floor/ceil/round) `:296` |
| `irtToBase(int $irt,int $priceIRT,int $scale=8): string` | IRT→base `:321` |
| `baseToIrt(string $amountBase,int $priceIRT,int $scale=0): string` | base→IRT `:334` |
| `trimZeros(string $value): string` | drop insignificant trailing zeros `:348` |

- **`normalize()`'s job:** turn any int/float/string into a canonical decimal
  string bcmath can parse — critically, render floats in fixed notation so
  `1.0E-7` never reaches bcmath. `:236-266`
- **Float-cast thresholds** (the only places Money touches native float):
  - `round()`: `round((float) bcmul($value,$factor,$scale+1))` where
    `factor=10^scale` `:288`. The float cast is exact while the scaled value
    stays under float's exact-integer ceiling ≈ `2^53`, i.e. below `10^(14−scale)`
    digits before the point at scale 0 (`10^14`), shrinking as scale grows.
  - `alignToTick()`: `floor/ceil/round((float) $div)` where `$div = value/tick`
    `:302-312` — same reachability.
  - **At real BTCIRT magnitudes (~1e11) these thresholds are unreachable**: the
    value/tick quotient (~1e11/10 ≈ 1e10) is far below 2^53, so no precision is
    lost in practice.

### 5.1 `declare(strict_types=1)` — which financial files have it, and why it matters

- **HAVE it:** `Money.php:2`, `GridPlanner.php:2`, `GridOrderSync.php:2`,
  `GridOrderExecutor.php:2`, `KillSwitchService.php:2`, `MarketDataLayer.php:2`,
  `AdjustGridJob.php:2`, `Math.php:2`, `OrderRegistry.php:2`, all DTOs/Enums,
  `SubmissionReconciler.php:3`, `ReconcileSubmissionsJob.php:3`,
  `NobitexService.php:2`, `NobitexWebSocketService.php:3`.
- **DEFERRED (no strict_types), by design:** `TradingEngineService.php:12-16`,
  `GridCalculatorService.php:11-15`, `CheckTradesJob.php:22-26`,
  `CompletedTrade.php:13-17` (and `BotConfig`/`GridOrder` models are untyped
  too). Rationale documented inline: `Money::normalize()` coerces float/int at
  every call site, and enabling strict types would change **int→string
  coercion** across these large files without a proven benefit (deferred as
  "Cleanup Phase 4").
- **Why it matters:** without `strict_types`, PHP **coerces** a scalar passed to
  a typed parameter; with it, PHP **rejects** the mismatch (TypeError). The
  deferred files rely on coercion at boundaries; the strict files reject, which
  is safer but was deferred where the coercion is already funneled through
  `Money`.

---

## 6. Kill switch (`KillSwitchService`)

`checkAndTrigger(BotConfig): {triggered, reason, details}` `KillSwitchService.php:45`.

- **No simulation guard** — runs for simulation bots too (intentional; locked by
  `KillSwitchServiceTest`). `:47-50`
- No-op if neither `stop_loss_percent>0` nor `max_drawdown_percent>0`. `:53-59`
- **Stop-loss metric** `evaluateStopLoss()` `:84`: anchor =
  **`grid_center_price`** (stable, not moving `center_price`). If anchor is
  null/blank/≤0 → skip. `distancePct = abs((current − anchor)/anchor × 100)` —
  **symmetric** (an up-move trips it too; a volatility breaker, not a directional
  stop). Trips when `distancePct > stop_loss_percent` (**strict `>`**). `:110-126`.
  A transient market-data failure does **not** trip (returns null). `:96-105`
- **Max-drawdown metric** `evaluateMaxDrawdown()` `:136`: sums **`net_profit` of
  completed trades where `net_profit < 0`** (losing trades only, bcmath over a
  cursor). `drawdownPct = abs(lossSum/total_capital × 100)`. Trips when
  `drawdownPct > max_drawdown_percent` (**strict `>`**). Needs positive
  `total_capital`. `:141-172`
- **Order:** stop-loss checked before max-drawdown. `:62-75`
- **`trip()`** `:192`: logs `KILL_SWITCH_TRIGGERED` (trading channel); if
  `is_active`, sets `is_active=false`, `stop_reason='kill_switch:'.reason`,
  `stopped_at=now()`, saves. **Idempotent** — if already inactive, still returns
  `triggered=true` (so `initializeGrid` aborts) but skips the redundant write.
  `:194-210`. Existing `cycle_exit` sells are **not** cancelled (open cycles can
  still close).
- **Callers:** `initializeGrid` (step 0), `AdjustGridJob` (per bot each cycle
  `AdjustGridJob.php:110`), and the lightweight `is_active` check in
  `createPairOrder`.

---

## 7. Exchange integration (`NobitexService`)

Base URL from `config('trading.nobitex.base_url')` (testnet vs
`https://apiv2.nobitex.ir`). Auth header `Authorization: Token {api_key}`.
`NobitexService.php:48-91`

### 7.1 Public methods (exact signatures)

ExchangeClient/DTO methods:
- `getOrderBook(string $symbol): OrderBookDto` — fallback chain v3 → v2(upper) →
  v2(dashed) → `/market/stats` synth. `:449`
- `createOrder(CreateOrderDto $dto): CreateOrderResponse` — POST
  `/market/orders/add`, **non-idempotent**. `:501`
- `cancelOrder(string $orderId): ApiOkDto` — POST `/market/orders/update-status`,
  **idempotent** (default retry). `:531`
- `getOrdersStatus(array $orderIds): array<OrderStatusDto>` — one POST
  `/market/orders/status` per id (no batch); returns `OrderStatusDto` **without
  clientOrderId**. `:559`
- `getBalance(string $currency): BalanceDto` `:715`
- `getWallets(): WalletsDto` `:734`

Reconciliation reads (Phase 12 Step 7, all read-only):
- `getOrderByClientOrderId(string $clientOrderId): ?array` — POST
  `/market/orders/status` with `clientOrderId`; returns **raw row** (with
  clientOrderId) or **null only on explicit NotFound**; every other failure
  throws. clientOrderId is **experimental** and searched only among
  open/active/inactive orders (a FILLED order answers NotFound). `:636`
- `listOpenOrders(string $symbol): array` — GET `/market/orders/list`
  (`status=open, details=2`). `:666`
- `listRecentTrades(string $symbol): array` — GET `/market/trades/list`. `:699`

Extra/positions/withdraws/whitelist:
- `createMarginOcoOrder(array $dto): array` (non-idempotent) `:753`
- `listPositions(?src,?dst,status='active',?page,?pageSize): array` `:762`
- `getPositionStatus(int $positionId): array` `:784`
- `closePosition(int $positionId, array $dto): array` (non-idempotent) `:790`
- `editCollateral(int $positionId, string $collateral): array` (non-idempotent) `:798`
- `createWithdraw(array $dto, ?string $totp=null): array` (non-idempotent) `:809`
- `confirmWithdraw(int $withdrawId, ?int $otp=null): array` (non-idempotent) `:818`
- `listWithdraws(?walletId,?page,?pageSize,?from,?to): array` `:826`
- `getWithdraw(int $withdrawId): array` `:845`
- `addressBookList(?network=null): array` `:851`
- `addressBookAdd(array $dto): array` (non-idempotent) `:858`
- `addressBookDelete(int $addressId): array` `:868`
- `activateWhitelist(): array` `:874`
- `deactivateWhitelist(string $otpCode, string $tfaCode): array` `:880`
- `getOptionsV2(): array` (cached 300s) `:892`
- `getWebsocketToken(): array` `:905`

Convenience:
- `getCurrentPrice(string $symbol): float` — WS snapshot first, else
  `/market/stats` (latest→bestSell/bestBuy mid). `:918`
- `getMarketStats(string $symbol): array` — spread/spreadPercent/dayChange. `:955`
- `getBalances(): array` — POST `/users/wallets/list` → `[cur => {available,
  locked}]` (available=`balance`, locked=`blocked`). `:989`
- `placeOrder(string $symbol, string $side, int $price, string $quantity,
  ?string $clientRef=null): array` — POST `/market/orders/add` with
  `clientOrderId=clientRef`, **non-idempotent**. `:1011`
- `healthCheck(): array` — GET `/users/profile`. `:1159`

### 7.2 Retry / rate limit / timeouts

- **Timeouts (connect vs read):** `timeout` (read, default `config` 10.0 /
  ctor env 8) and separate `connectTimeout` (default 5.0) — distinguishes
  "couldn't reach server" from "server slow". `NobitexService.php:61-64,79-82`.
- **Retry:** `retryTimes = retry.times` (default 3), `retrySleepMs = retry.sleep`
  (200). Loop with exponential-backoff-with-jitter
  (`baseSleepMs*(attempt+1)+random(0..250)`); honours a numeric `Retry-After`
  header on 429 capped by `retry.max_ms` (4000). `:142-211,335-363`.
- **Retryable statuses:** `config('trading.nobitex.retry.http_statuses')` =
  `[408,429,500,502,503,504]`; classification is by exception **type + HTTP
  status**, never message text. `:304-333`.
- **Idempotency policy (`$idempotentRetry`):** default true (all GETs,
  cancelOrder, reads). Order-creating / value-moving POSTs pass **false**: a
  `ConnectionException` (connect/read timeout indistinguishable) or a server
  408/5xx becomes an **`AmbiguousOrderSubmissionException`** (surface, don't
  resend); only a **429 stays retryable** for these (definitive rejection).
  `:109-211,222-229,304-318`.
- **Rate limiter:** dark by default. `rate_limit.enforce=false` → legacy soft
  per-route `RateLimiter::attempt(rpm)` + 200ms nap, never actually blocks
  `:132-140`. `enforce=true` → global blocking gate `rateGate()` inside the retry
  loop, one account-wide key `'global'`, blocks up to `max_wait_ms` (10000) then
  throws `RateLimitExceededException` (non-retryable). `:152-153,263-268`.

### 7.3 WebSocket cache keys and readers

`NobitexWebSocketService` subscribes to `public:orderbook-{SYMBOL}` (Centrifugo)
and **writes two cache keys** on each publication `NobitexWebSocketService.php:293-308`:
- `MarketDataLayer::CACHE_PREFIX_ORDERBOOK . SYMBOL` = `"mdl:orderbook:{SYMBOL}"`
  → `{asks,bids,lastTradePrice,lastUpdate}`.
- `MarketDataLayer::CACHE_PREFIX_PRICE . SYMBOL` = `"mdl:last_price:{SYMBOL}"`
  → `{price,ts}`.
Prefix constants: `MarketDataLayer.php:26-27`. TTLs: price
`trading.cache.price_ttl` (30 via env chain), orderbook
`trading.cache.market_stats_ttl` (300).

**Readers:** `MarketDataLayer::getLastPrice()` / `getOrderBook()` read these keys
first (Cache), then WS in-memory snapshot, then REST
(`MarketDataLayer.php:76-122,129-158`). `NobitexService::getCurrentPrice()` reads
the WS **in-memory** snapshot (`getLastPriceSnapshot`) not the cache
(`NobitexService.php:922-931`). `KillSwitchService`, `CheckTradesJob`
(simulation), `ReadMarketStatsJob` all consume price via `MarketDataLayer`.

---

## 8. Configuration surface

### 8.1 `config/trading.php` (default in parentheses)

- `simulation_mode` (`false`), `enable_scheduler` (`true`). `:11-12`
- **`min_order_value_irt`** (`3_000_000`). `:14`
- `ticks.BTCIRT` (`10`), `ticks.ETHIRT` (`10`), `ticks.USDTIRT` (`10`). `:21-25`
- `exchange.name` (`nobitex`); **`exchange.fee_bps`** (`35` = 0.35%);
  `exchange.fee_rate_percent` (0.35); `exchange.slippage_bps` (`10`);
  `exchange.allowed_symbols` (`BTCIRT,ETHIRT,USDTIRT`). `:32-44`
- `exchange.precision`: BTCIRT `{price 0, qty 8}`, ETHIRT `{0,6}`, LTCIRT `{0,6}`,
  USDTIRT `{0,2}`. `:46-51`
- `nobitex.http.timeout` (`10.0`), `nobitex.http.connect_timeout` (`5.0`),
  `nobitex.http.user_agent`. `:69-73`
- `nobitex.retry.times` (`3`), `retry.sleep` (`200` ms), `retry.initial_ms`
  (`500`), `retry.max_ms` (`4000`), `retry.factor` (`2.0`), `retry.jitter_ms`
  (`250`), `retry.http_statuses` (`[408,429,500,502,503,504]`). `:76-87`
- `nobitex.rate_limit.enforce` (`false`), `rpm` (`60`), `window_seconds` (`60`),
  `max_wait_ms` (`10000`). `:90-106`
- `nobitex.auth.*` (username/password/remember/totp/login_path/auto_refresh/
  cache_key `nobitex:api_token`). `:109-117`
- `reconcile.enabled` (`true`), `min_age_seconds` (`300`),
  `pending_min_age_seconds` (`900`), `not_found_confirmations` (`2`),
  `cancel_on_not_found` (`true`), `max_attempts` (`12`), `max_age_hours` (`6`).
  `:129-153`
- `grid.default_capital` (`100_000_000`), `default_active_percent` (`30`),
  `default_spacing_percent` (`1.5`), `default_levels` (`10`),
  `max_active_percent` (`80`), **`default_stop_loss_percent`** (`15`),
  **`max_drawdown_percent`** (`25`), `simulation` (`false`). `:160-169`
- `cache.price_ttl` (30 env chain), `cache.market_stats_ttl` (`300`),
  `cache.balance_ttl` (`60`), `cache.prefix`. `:176-181`
- `scheduler.interval_check_trades` (`60`), `interval_adjust_grid` (`600`),
  `align_to_minute` (`true`), `jitter` (`0`) — see §1.2 discrepancy note. `:188-193`
- `flags.*`, `security.admin_ip_whitelist` (`127.0.0.1,::1`), etc. `:200-221`

> **Fee-source split:** `GridCalculatorService` uses constants
> `NOBITEX_FEE_RATE=0.25` (%) / `EXCHANGE_SLIPPAGE=0.1` for its *estimator*
> (`GridCalculatorService.php:32-33`), whereas real booked trades use `fee_bps`
> (default 35 = 0.35%). Two different fee numbers coexist by design.

### 8.2 `bot_configs` user-set columns (migration types/defaults)

From `2025_07_24_214742_create_bot_configs_table.php` and later migrations:

| Column | Type | Default |
|---|---|---|
| `name` | string | `'Grid Bot #1'` |
| `is_active` | boolean | `false` |
| `total_capital` | DECIMAL(20,0) | `100000000` |
| `active_capital_percent` | DECIMAL(5,2) | `30` (later default-adjusted, see `2025_11_07_120000`) |
| `grid_spacing` | DECIMAL(5,2) | `1.5` |
| `grid_levels` | integer | `10` |
| `center_price` | DECIMAL(20,0) | nullable |
| `stop_loss_percent` | DECIMAL(5,2) | `5` |
| `symbol` | string(32) | `'BTCIRT'` |
| `mode` | string(8) | `'buy'` (buy\|sell\|both) |
| `levels` | integer | `3` |
| `step_pct` | DECIMAL(8,3) | `0.250` |
| `budget_irt` | unsignedBigInteger | `0` |
| `simulation` | boolean | `true` |
| `min_order_value_irt` | unsignedBigInteger | nullable |
| `fee_bps` | unsignedSmallInteger | `35` |
| `qty_decimals` | unsignedTinyInteger | `8` |
| `tick` | unsignedInteger | `10` |
| `take_profit_percent` | DECIMAL(5,2) | nullable |
| `max_drawdown_percent` | DECIMAL(5,2) | nullable |
| `rebalance_threshold` | DECIMAL(5,2) | `5.0` |
| `init_status` | string(32) | nullable (`running`/`partially_initialized`/`failed`) |
| `open_cycles_count` | TINYINT UNSIGNED | nullable |
| `capital_locked_irt` | DECIMAL(20,0) | nullable (not cast) |
| `grid_center_price` | DECIMAL(20,0) | nullable (not cast) |

Model casts: `BotConfig.php:62-102` (`simulation`/`is_active` boolean;
`grid_spacing` decimal:2; `total_capital` decimal:0; `open_cycles_count` integer;
`capital_locked_irt`/`grid_center_price` deliberately **uncast**). Model
`creating` defaults: symbol→BTCIRT, mode→buy, levels→grid_levels||3,
step_pct→grid_spacing||0.250, simulation→true, active_capital_percent→100.
`BotConfig.php:455-463`.

> **UNCERTAIN:** `fee_bps` is not exposed in any Filament form (per
> `CompletedTrade.php:391`), so the NOT NULL DEFAULT 35 governs it in practice.
> `stop_loss_percent` migration default (5) differs from
> `grid.default_stop_loss_percent` config (15).

### 8.3 `grid_orders` columns

Base `2025_07_24_215103`: `price` DECIMAL(20,0), `amount` DECIMAL(20,8),
`type` ENUM(buy,sell), `status` ENUM, `nobitex_order_id` nullable.
Later: `paired_order_id` (2025_07_26); `filled_at` (2025_10_21);
`client_order_id` (unique) + `exchange_order_id` (2026_05_20);
status ENUM extended to include `failed, partially_filled, submission_unknown`
(2026_06_25); `original_amount, filled_amount, remaining_amount` DECIMAL(20,8),
`average_fill_price` DECIMAL(20,0), `last_fill_at` (2026_06_27_000001, Phase 9
Step 1); `role` ENUM(initial_grid,cycle_exit,rebalance,manual) nullable
(2026_06_27_000002); `reconcile_attempts`/`reconcile_not_found_count` (uint,
default 0), `reconcile_last_attempt_at` (2026_07_20). `price` also forced to
DECIMAL(20,0) + CHECK(price>0) (2025_11_07). Model saving-guard rejects
non-positive price and >20-digit price `GridOrder.php:103-116`.

### 8.4 `completed_trades` columns

Base `2025_07_24_215225`: `buy_price`/`sell_price`/`profit`/`fee` DECIMAL(20,0),
`amount` DECIMAL(20,8). `buy_order_id`/`sell_order_id` FKs (2025_11_16).
Advanced (2025_11_17): `gross_profit`/`net_profit` DECIMAL(20,8),
`profit_percentage` DECIMAL(10,4), `execution_time_seconds` int,
`market_conditions` json, `trade_type` string(50) default `grid`,
`grid_level_buy`/`grid_level_sell` int, `slippage` DECIMAL(10,4), `notes`.
Model casts (`CompletedTrade.php:43-55`): **`profit` cast `decimal:0`** while
`gross_profit`/`net_profit` cast `decimal:8` — see §10.

---

## 9. Simulation vs live — every branch

Branches on `$botConfig->simulation` (file:line, what differs):

- `TradingEngineService::performPreflightChecks` `:247-250` — **simulation
  skips** API-key + `healthCheck` (this is the *simulation-independence* guard).
- `verifySufficientQuoteBalance` `:402` — simulation returns success (no balance
  check).
- `placeGridOrders` base-currency SELL pre-fetch guarded by `!simulation`
  `:613`; preset sizing null for simulation (`computePresetBaseQty` returns null
  when balance null) `:807-811`.
- `cleanupExistingOrders` `:530-542` — simulation **logs** "would cancel", live
  actually cancels.
- `GridOrderExecutor::applyForBot` `:75-79,144-187` — simulation logs/creates
  `placed` rows with `SIM-*` ids, no API call; live goes pending→placed and can
  end submission_unknown.
- `GridOrderExecutor::apply` (legacy) `:325-329,368-375` — same split.
- `CheckTradesJob::processBot` `:117-121` — simulation → `checkSimulatedOrders`
  (local price match), live → `checkOrdersStatus` (Nobitex API).
- `CheckTradesJob::createPairOrderLocked` `:839-861` — simulation places
  `SIM-*` pair, live calls `placeOrder`.
- `AdjustGridJob` `:120,272` — `$simulate = (bool)$bot->simulation` passed to
  `applyForBot(role:'rebalance')`.
- `NobitexService::healthCheck` `mode` field reflects
  `config('trading.grid.simulation')`. `:1176,1189`

**Deliberately NOT branched on simulation:**
- `KillSwitchService::checkAndTrigger` — **no simulation guard**, runs for paper
  bots (`KillSwitchService.php:47-50`).
- `GridOrderObserver` — recomputes inventory for all bots regardless of mode
  (no `simulation` reference anywhere in the observer).

---

## 10. Known intentional behaviours & latent risks

- **Symmetric kill switch** — `abs(...)` makes an *up*-move trip the stop-loss;
  a volatility breaker, not a directional stop. Intentional; locked by
  `KillSwitchServiceTest`. `KillSwitchService.php:110-117`.
- **Kill switch on simulation bots** — no simulation guard so paper trading is
  faithful. Locked by `KillSwitchServiceTest`. `KillSwitchService.php:47-50`.
- **`fee_bps = 0` taken literally** — `?? config` fires only on null, so a
  deliberate 0 means zero fee; safe because the column is NOT NULL DEFAULT 35 and
  not exposed in any Filament form. `CompletedTrade.php:391-394`.
- **Execution-time formatter returns null at zero** — a same-second (0s) fill
  shows empty, not "0s" (falsy check treats 0 and null alike). Locked by
  `CompletedTradeExecutionTimeFormatTest`. `CompletedTrade.php:241-248`.
- **`profit` decimal(20,0) vs cast history** — `profit` is a DECIMAL(20,0) column
  cast `decimal:0`, but it stores `net_profit` (which can carry fractional IRT
  before the column truncates), while `gross_profit`/`net_profit` are DECIMAL(20,8)
  cast `decimal:8`. Writes go through the string-binding mutators (`setProfit`,
  etc.) to avoid 32-bit PARAM_INT truncation of ~20-digit IRT.
  `CompletedTrade.php:43-55,81-127`.
- **strict_types deferral** — `TradingEngineService`, `GridCalculatorService`,
  `CheckTradesJob`, `CompletedTrade`, `BotConfig`, `GridOrder` omit
  `declare(strict_types=1)` on purpose (documented "Cleanup Phase 4"); they rely
  on `Money::normalize()` coercion at boundaries. Coercion vs rejection: see §5.1.
- **`collapsed_levels` tick behaviour** — when two adjacent levels round to the
  same tick they collapse to one order (`collapsed_levels` counter); the grid can
  thus place fewer orders than `levels`. `GridPlanner.php:116-123`.
- **`submission_unknown` ambiguity** — an order-POST that fails ambiguously is
  parked (not marked cancelled) to avoid duplicate real orders; resolved only by
  `SubmissionReconciler` via read-only lookups + trade-history corroboration.
  `GridOrderExecutor.php:257-275`, `SubmissionReconciler.php:20-61`.
- **Cross-bot capital race** — `verifySufficientQuoteBalance` checks this bot vs
  total account balance; two bots starting concurrently can both pass against the
  same pre-spend balance (no reserved-capital concept). `TradingEngineService.php:388-398`.
- **`GridOrderExecutor::apply()` legacy path** — unscoped, creates no rows / no
  dedup; production must use `applyForBot`. `GridOrderExecutor.php:297-302`.
- **AdjustGridJob symbol whitelist** — uses `env('TRADING_ALLOWED_SYMBOLS',
  'BTCIRT')`, **not** `config('trading.exchange.allowed_symbols')`.
  `AdjustGridJob.php:69`.

---

## 11. Operational facts

- **PHP binary for cron/WS keep-alive:** `/usr/local/bin/ea-php83` (cPanel;
  falls back to `ea-php84` then `php`). `scripts/ws-keepalive.sh:9-16`. The
  keep-alive starts `artisan nobitex:ws-consumer BTCIRT,ETHIRT,USDTIRT`
  (`scripts/ws-keepalive.sh:26`).
- **Cache driver:** `CACHE_STORE` default **`database`** (`config/cache.php:17`);
  `lock_connection` = `default`. `AppServiceProvider` warns if the driver isn't
  `database`/`redis`, because `Schedule::onOneServer()` / `Cache::lock()` need
  atomic locks (`AppServiceProvider.php:58-69`).
- **Timezone:** `config('app.timezone')` = `env('APP_TIMEZONE','UTC')`
  (`config/app.php:32`); the scheduler uses `app.timezone`
  (`Console\Kernel::scheduleTimezone()` `Kernel.php:33`). Host OS is
  **Asia/Tehran** (per task context) — a UTC app timezone on an Asia/Tehran host
  means scheduled times are interpreted in UTC unless `APP_TIMEZONE` is set.
- **WebSocket cache prefixes:** `mdl:last_price:{SYMBOL}` and
  `mdl:orderbook:{SYMBOL}` (see §7.3).
- **Log channels:** `trading` and `nobitex` (plus `queue`, `scheduler`, etc.),
  all tapped by `App\Logging\CustomizeFormatter` (single-line Monolog format).
  `config/logging.php:54-71`.
- **Migrations:** `php artisan migrate` (`README.md:86`); the reconcile-tracking
  migration explicitly notes it "Requires `php artisan migrate` on the host".
- **Test suite:** run with `php artisan test` (`composer.json` `test` script).
  **20 `*Test.php` files, 246 test methods** counted (`public function test*` /
  `#[Test]` / `@test`). The stated "300 tests" likely counts data-provider
  expansions on top of the 246 methods — **UNCERTAIN** exact runner total.
- **Artisan commands:** `grid:run` (`GridRunOnce`, registered in
  `Console\Kernel::$commands`), `grid:reconcile-submissions`, `ws:nobitex`,
  `nobitex:ws-consumer`, `nobitex:test`, `test:nobitex-api`, `test:check-trades`.
