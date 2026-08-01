# Panel Audit — Filament admin panel vs. the trading engine

**Status:** INVESTIGATION ONLY. No production code, view, migration, config, or
test was changed by this task. This document is the sole deliverable. It maps
every Filament page / widget / Livewire component to its class, its view, and
its real data source, and assigns a verdict + recommendation to each.

**Method:** static reading of `app/Filament/**`, `app/Livewire/**`,
`app/Providers/Filament/AdminPanelProvider.php`, `routes/web.php`,
`routes/console.php`, the models under `app/Models`, and the engine services
under `app/Services`. `vendor/` is not installed here, so nothing was executed;
every claim is cited to `file:line`.

**Note on the engine reference doc.** The task pointed at `docs/bot-inventory.md`.
That file does not exist in this repo. The engine investigation that *does*
exist is `docs/phase13-investigation.md` (financial/grid test-coverage audit),
which was read and used as the ground truth for "what the backend can actually
do." Where this report says a backend method exists / does not exist, it was
verified directly against `app/Services/*` as well.

---

## 0. The single most important finding: there are TWO parallel bot systems

Almost every discrepancy in the panel traces back to this. The codebase
contains two unconnected "bot" data paths:

| | **System A — BotConfig engine** | **System B — GridRun CLI recorder** |
|---|---|---|
| Config/anchor table | `bot_configs` | `grid_runs` (+ `grid_run_orders`, `grid_events`) |
| How a row is created | **only** the Filament *Grid Bots* form (`CreateBotConfig`) | **only** the CLI `php artisan grid:run` command |
| Runtime engine | `TradingEngineService::initializeGrid` + scheduled `CheckTradesJob` / `AdjustGridJob` (`routes/console.php:28`) writing `grid_orders`, `completed_trades`, `bot_activity_logs` | `GridRunRecorder` writing `grid_runs` / `grid_run_orders` / `grid_events` |
| Linked to the other? | — | `grid_runs.bot_id` is fillable but **`GridRunOnce` never sets it** (`GridRunOnce.php` passes no `bot_id`), so every run is orphaned from any BotConfig |
| Which panel pages read it | Dashboard, Bot Monitoring, Bot Intelligence, Grid Bots | **only** Grid Runs |

The mature, 329-test backend is System A. The 10 rows the user sees under
**Grid Runs** are System B — produced by running the `grid:run` artisan command,
which writes to `grid_runs` and touches neither `bot_configs` nor the dashboard's
tables. Nothing in `app/` creates a `BotConfig` except the Filament form
(`grep BotConfig::create` over `app/` + `database/` returns nothing outside
tests). So if no bot was ever created through the *Grid Bots* form,
`bot_configs` is empty — and every status page that keys off it reads zero,
regardless of how many CLI `grid:run` rows exist.

---

## 1. Summary table

| Sidebar item | Class | View | Primary data source | Verdict | Recommendation |
|---|---|---|---|---|---|
| داشبورد (Dashboard) | `Filament\Pages\Dashboard` (default) + 3 widgets | Filament default | `bot_configs`, `completed_trades`, `grid_orders`, WS cache | **PARTIAL** | COMPLETE |
| — Bot status stats | `App\Filament\Widgets\BotStatusWidget` | — (StatsOverview) | `bot_configs` + `completed_trades` + `grid_orders` | REAL (but empty w/o System A) | KEEP |
| — 30-day chart | `App\Filament\Widgets\PerformanceChartWidget` | — (ChartWidget) | `completed_trades.profit` | REAL (empty w/o data) | KEEP |
| — WS health | `App\Filament\Widgets\WebSocketHealthWidget` | `filament/widgets/web-socket-health-widget` | WS price cache via `WebSocketHealthService` | REAL | KEEP |
| Bot Monitoring | `App\Filament\Pages\BotMonitoring` | `filament/pages/bot-monitoring` | `bot_configs`+`grid_orders`+`completed_trades`+`bot_activity_logs` | **PARTIAL** | COMPLETE or MERGE |
| Grid Runs | `App\Filament\Resources\GridRunResource` | Filament resource table + `view-grid-run` | `grid_runs` / `grid_run_orders` / `grid_events` (System B) | **REAL** | KEEP |
| آزمایش اتصال (Connection Test) | `App\Filament\Pages\ConnectionTest` | `filament/pages/connection-test` | `NobitexService` live API + cache | **REAL** (sim branch faked) | KEEP |
| گفتمان / یادداشت‌ها (Notes) | `App\Filament\Pages\Notes` | `filament/pages/notes` | `storage/app/notes/user_notes.json` | **REAL but off-domain** | REMOVE (or move out) |
| Grid Calculator نسل آینده 4.0 | `App\Filament\Pages\GridCalculator` | `filament/pages/grid-calculator` | mostly stubs / `rand()` / non-existent service methods | **SHELL** | REMOVE + rebuild small |
| ربات‌های گرید (Grid Bots) | `App\Filament\Resources\BotConfigResource` | Filament resource forms/table | `bot_configs` + `TradingEngineService` | **REAL** | KEEP |
| Bot Intelligence | `App\Filament\Pages\BotIntelDashboard` | `filament/pages/bot-intel-dashboard` | `bot_configs`+`grid_orders`+`completed_trades`+`bot_activity_logs` | **PARTIAL/broken** | COMPLETE or REMOVE |
| *(orphans — not in sidebar)* | 5 Livewire components + `RunStats` widget + dev routes | see §7 | mixed | mixed | REMOVE most |

Panel registration is in `AdminPanelProvider.php`: pages/resources/widgets are
auto-discovered (`discoverResources`/`discoverPages`/`discoverWidgets`,
`AdminPanelProvider.php:645-657`) with `Dashboard`, `ConnectionTest`,
`GridCalculator`, `Notes` also explicitly listed (`:647-652`).

---

## 2. Dashboard (داشبورد)

**Class:** default `Filament\Pages\Dashboard` (`AdminPanelProvider.php:648`).
The page itself is stock Filament; its content is three auto-discovered widgets.

**Widget 1 — `BotStatusWidget`** (`app/Filament/Widgets/BotStatusWidget.php`),
the "X of Y active" / "capital IRT" tiles:
- `activeBots = BotConfig::where('is_active', true)->count()` (`:20`)
- `totalBots  = BotConfig::count()` (`:21`) → renders **"X از Y فعال"** (`:55`)
- `totalCapitalIRT = BotConfig::sum('total_capital')` (`:24`) → **"سرمایه کل … ریال"** (`:61`)
- profit tiles from `CompletedTrade::…sum('profit')` (`:33,36,50`)
- active orders from `GridOrder::where('status','placed')->count()` (`:41`)

**Widget 2 — `PerformanceChartWidget`** (30-day chart): cumulative
`CompletedTrade::whereDate('created_at',$date)->sum('profit')` over 30 days
(`PerformanceChartWidget.php:26-34`). Note the label reads "سود/زیان تجمعی ($)"
and the y-axis is formatted with a `$` sign (`:41,84`) even though the whole app
is IRT — a cosmetic bug.

**Widget 3 — `WebSocketHealthWidget`**: reads `WebSocketHealthService::getStatus()`
(`WebSocketHealthWidget.php:23`), which is cache-backed off the WS price feed
(`WebSocketHealthService.php:21`, keys written by the `grid:ws:consume` consumer).
Degrades to "WS health data unavailable" on any throw (`:48-54`). This one is
**REAL** and independent of System A/B.

**Verdict: PARTIAL.** The widgets are correctly wired to real models; they are
not mock. But the two headline tiles ("0 of 0 active", "0 IRT") read **System A**
(`bot_configs`), which is empty unless bots were created through the *Grid Bots*
form. There is no fake data — the zeros are truthful for an empty `bot_configs`.

### Answer: "0 of 0 active / 0 IRT" vs. Grid Runs showing 10 rows
- Dashboard capital/bot counts come from `bot_configs`:
  `BotStatusWidget.php:20-24`.
- Grid Runs come from `grid_runs` via `GridRunResource` → `GridRun` model
  (`GridRun.php:16` `$table='grid_runs'`), and rows are created **only** by
  `GridRunRecorder::start()` → `GridRun::create()` (`GridRunRecorder.php:46`),
  called **only** from the CLI `grid:run` command (`GridRunOnce.php:31-39`).
- The two tables are never joined and the CLI path never populates `bot_id`
  (`GridRunOnce.php` sets no `bot_id`) nor creates a `BotConfig`. So 10 CLI runs
  exist in `grid_runs` while `bot_configs` has 0 rows → dashboard shows 0/0 and
  0 IRT. **This is System A vs System B (see §0), not a bug in the widget query.**

**Recommendation: COMPLETE.** Decide the source of truth. If `bot_configs` is
the intended model of a "bot" (it is — the engine and scheduler run off it),
either (a) have the panel's status tiles also surface System-B `grid_runs`
activity, or (b) retire the standalone `grid:run` CLI path. Backend dependency:
none new — both tables already exist; this is a product/UX decision plus wiring.
Also fix the `$` label on the 30-day chart (`PerformanceChartWidget.php:41,84`).

---

## 3. Bot Monitoring

**Class:** `app/Filament/Pages/BotMonitoring.php` · **View:**
`resources/views/filament/pages/bot-monitoring.blade.php`.

**Data source (all System A):** iterates `BotConfig::where('is_active',true)->get()`
(`:26`) and for each active bot reads:
- active/filled `grid_orders` via `$bot->gridOrders()` (`:32-46,69-86,137-141`),
- `completed_trades` for the bot (`:45,104,142-145`),
- `bot_activity_logs` grouped into CHECK_TRADES cycles (`:114-120`, helper
  `groupLogsToCycles` `:193-323`).

Everything is real query logic against real engine tables. `bot_activity_logs`
is genuinely written by the engine (`BotActivityLogger::log` → `BotActivityLog::create`,
`BotActivityLogger.php:11`, invoked throughout `CheckTradesJob`). It wraps the
activity-log read in a try/catch that falls back to empty if the table is absent
(`:113-133`).

**Verdict: PARTIAL.** The code is correct and non-mock, but the page renders
**nothing** unless `bot_configs` has an `is_active=true` row *and* the scheduled
`CheckTradesJob` has run against it. With System A empty, the page shows an empty
state. Uses `$o->type` for order side (`:159`) — correct for the `grid_orders`
schema (`type` enum, see §6), unlike Bot Intelligence.

**Recommendation: COMPLETE or MERGE.** It genuinely overlaps Dashboard and Bot
Intelligence (all three are "status of active bots" views — see §8). Pick one
detailed monitoring page and fold the other two into it. No backend work needed
— it reads tables the engine already populates.

---

## 4. Grid Runs

**Class:** `app/Filament/Resources/GridRunResource.php` · **Model:** `GridRun`
(`grid_runs`) · **View:** Filament resource table + custom
`resources/views/filament/resources/grid-run-resource/pages/view-grid-run.blade.php`;
per-record stats via `GridRunResource/Widgets/RunStats.php`; relation managers
for events and orders (`GridRunResource.php:102-108`).

**Data source:** straight Eloquent over `grid_runs` (`GridRunResource.php:26-99`),
`events()`→`grid_events` and `orders()`→`grid_run_orders`
(`GridRun.php:57-65`). Rows are written by `GridRunRecorder` (System B) from the
`grid:run` CLI command (`GridRunOnce.php`).

**Verdict: REAL.** This is the one status surface backed by rows that actually
exist in this environment. Columns (symbol/mode/levels/step/budget/status/sim,
event & order counts) all map to real fields. `RunStats` widget is real per-run
aggregation (`RunStats.php:15-40`).

**Recommendation: KEEP.** It is the truthful record of `grid:run` executions.
The only concern is conceptual: it exposes System B while everything else exposes
System A. If System B is retired (see §2 recommendation), this page goes with it;
if kept, consider linking runs back to a `BotConfig` by finally populating
`grid_runs.bot_id`.

> Data-integrity aside (not a panel bug): the repo contains
> `add_bot_fk_to_grid_runs` and `create_grid_run_orders_table` migrations but no
> `create_grid_runs`/`create_grid_events` **create** migration is present in
> `database/migrations`. The tables clearly exist at runtime (the screenshots
> show data), so the create migrations were applied historically / outside the
> tracked set. Worth confirming before the overhaul so a fresh `migrate` builds
> System B.

---

## 5. آزمایش اتصال (Connection Test)

**Class:** `app/Filament/Pages/ConnectionTest.php` · **View:**
`resources/views/filament/pages/connection-test.blade.php`.

**Data source:** live Nobitex calls through the real `NobitexService`:
- price → `NobitexService::getCurrentPrice('BTCIRT')` (`:175`)
- balance → `getBalances()` (`:202`)
- orderbook → `getOrderBook('BTCIRT')` (`:237`)
- health → `healthCheck()` (`:265`)
- connection status cached under `nobitex_connection_status` (`:128,309`)

One caveat: the **connection** button in *simulation mode* fakes the result —
`sleep(1)` + `rand(50,200)` ms and a hardcoded success (`:94-106`), rather than
hitting the API. The price/balance/orderbook/health buttons always go through
`NobitexService` regardless of mode.

**Verdict: REAL.** It is a genuine, useful API diagnostic wired to the working
network layer. The only mock is the simulation-mode latency of the connection
ping (clearly labelled "(شبیه‌سازی)").

**Recommendation: KEEP.** Minor polish: make the simulation branch say "skipped
(simulation)" instead of a fabricated latency, so it can't be mistaken for a
real measurement.

---

## 6. Bot Intelligence

**Class:** `app/Filament/Pages/BotIntelDashboard.php` · **View:**
`resources/views/filament/pages/bot-intel-dashboard.blade.php`.

**Intended purpose:** a per-bot "intelligence" dashboard — snapshot metrics
(status, capital in use, grid health, cycles, win rate, avg cycle duration),
a grid map, open orders, completed pairs, capital concentration, grid drift,
system health, and an activity timeline. All keyed to one selected `BotConfig`.

**Data source (System A):** on mount it selects a bot with
`BotConfig::active()->first() ?? BotConfig::first()` (`:33`). Metrics read
`$bot->gridOrders()`, `CompletedTrade::where('bot_config_id',…)`, and
`BotActivityLog::where('bot_config_id',…)` (`:76-96,203,428-451`).

**Why "No Bot Selected":** `mount()` (`:30-35`) sets `selectedBot` to the first
active or first bot; with `bot_configs` empty (System A, §0) both return `null`,
so `selectedBotId` stays null and the view renders the empty state / "No Bot
Selected". `getGridDrift()` literally returns `'No bot selected'` when
`$selectedBot` is null (`:369`).

**Additional real bug (independent of empty data):** this page reads the order
**side** from a column named `side` — e.g. `$activeOrders->where('side','buy')`
(`:163,333,386`), `$order->side` (`:250,285,336`). But `grid_orders` has **no
`side` column** — the column is `type` enum `['buy','sell']`
(`create_grid_orders_table` migration `:20`; `GridOrder.php:16` fillable lists
`'type'`). So even with an active bot that has live orders, `->where('side',…)`
matches nothing and `$order->side` is null: **Grid Health, Capital Concentration,
Grid Drift, and the grid map's side labels would all be wrong/blank.**
`BotMonitoring` gets this right (`$o->type`); `BotIntelDashboard` does not.

**Verdict: PARTIAL (and partly broken).** The scaffolding, empty-state handling,
and log/trade queries are real, but the core order-distribution features are
wired to a non-existent column and would misreport even when data exists.

**Recommendation: COMPLETE or REMOVE.** If kept: (a) fix `side`→`type`
throughout, (b) resolve the overlap with Dashboard/Bot Monitoring (§8). Backend
dependency: none — all tables/columns exist; this is a field-name fix plus a
data-availability (System A populated) precondition.

---

## 7. Grid Calculator نسل آینده 4.0

**Class:** `app/Filament/Pages/GridCalculator.php` (3,297 lines) · **View:**
`resources/views/filament/pages/grid-calculator.blade.php`.

This page is a facade of AI/quantum/neural/sentiment terminology over stub
methods. The genuine engine calculator (`GridCalculatorService::calculateGridLevels`
/ `calculateOrderSize` / `calculateExpectedProfit` / `assessGridRisk`) **is never
called by this page** — the page calls *differently named* methods that do not
exist on the service.

### 7a. The runtime error "System requirement not met: network"
- `mount()` → `verifySystemRequirements()` (`:191-208`).
- The `network` requirement is `checkNetworkAccess()` (`:385-407`), which does a
  raw `@file_get_contents()` against `https://api.nobitex.ir/market/stats` and
  `https://api.binance.com/api/v3/ping` (`:387-390`) — bypassing the configured
  `NobitexService`/proxy entirely, and calling **Binance** directly.
- If neither URL is reachable (locked-down host, blocked egress, or the proxy
  the rest of the app uses), it returns `false` (`:406`), and
  `verifySystemRequirements()` **throws** `RuntimeException("System requirement
  not met: network")` (`:203`). That is exactly the error in the screenshot; it
  fires on page load, before any tab is usable.

### 7b. Are ANY of the four tabs real? — each one, with its backing
| Tab / button | Entry method | What it actually does | Backed? |
|---|---|---|---|
| **AI super-optimization** | `runSuperAIOptimization()` (`:928`) | calls `runGeneticOptimization()` etc. which just `return ['improvement'=>rand(5,15)]` (`:2873-2892`); then calls `performQuantumCalculation()` | **SHELL** (random numbers) |
| **Neural prediction** | `runNeuralPrediction()` (`:966`) | `loadNeuralModels()`/`predictPrice()`/`predictVolatility()` are stubs returning hardcoded/`rand()` values (`:2894-2895`, `:1218-1300`); `runNeuralPredictions()` is empty `{}` (`:2894`) | **SHELL** |
| **Quantum calc** | `performQuantumCalculation()` (`:880`) | `runCoreCalculations()` (`:1064`) calls `GridCalculatorService::calculateAdvancedGridLevels()`, `calculateQuantumOrderSizing()`, `calculateMultiLayerProfit()`, `calculateQuantumRisk()` — **none of these methods exist** on the service (it only has `calculateGridLevels`/`calculateOrderSize`/`calculateExpectedProfit`/`assessGridRisk`). Call → `Error: undefined method` → caught by `handleCalculationError()`. `initializeQuantumProcessor()` hardcodes `return false` (`:2553`, "2030 feature"), so `runQuantumCalculations()` early-returns anyway (`:1120-1123`). | **SHELL** (calls non-existent backend) |
| **Social sentiment scan** | `scanSocialSentiment()` (`:2973`) | body is a single `Notification::make(...)->send()` toast — no computation (`:2973-2979`). Same for `analyzeBlockchainData`, `trackWhaleActivity`, `simulateFlashCrash`, `runExtremeStressTest`, `exportQuantumReport`, `saveNeuralPreset` (`:2965-3011`). | **SHELL** (toast only) |

Supporting evidence that the "systems" are decorative: the "data source" and
"AI" initializers are one-liner stubs — `connectBinanceAPI(): bool { return true; }`,
`connectSocialFeeds(): bool { return true; }`, `analyzeWhaleActivity(): array { return []; }`,
`loadNeuralNetwork(): bool { return Cache::get('neural_network_ready', false); }`,
`initializeSentimentEngine(): bool { return true; }` (`:2526-2556`). The 12-"dimension"
market analysis (`:1146-1166`) and on-chain/social analyzers all return `[]`.

The **only** genuine arithmetic on the page is the two inline display helpers
`getActiveCapital()` = `total_capital*active%/100` (`:3017-3023`) and
`getOrderSize()` = `activeCapital/levels` (`:3028-3034`) — trivial form math, not
the engine.

Also note the view binds its main submit button to a Livewire method that does
not exist: `grid-calculator.blade.php:131` `wire:submit.prevent="calculateGrid"`,
but the page has **no** `calculateGrid()` method (its public methods are
`performQuantumCalculation`, `runSuperAIOptimization`, `runNeuralPrediction`,
etc.). So even if the network gate passed, the primary "Calculate" action is a
dead binding.

**Verdict: SHELL.** No tab is backed by working code. The one real service that
could power a calculator (`GridCalculatorService`) is not invoked; the methods
the page *does* invoke on it don't exist.

**Recommendation: REMOVE and replace with a small, honest calculator.** A real
grid calculator is ~1 page: call the existing `GridCalculatorService::calculateGridLevels`
+ `calculateOrderSize` + `calculateExpectedProfit` (all real, and flagged as
unit-testable in `phase13-investigation.md §2.3`). Missing backend for the
advertised features: neural prediction engine, quantum optimizer, multi-source
sentiment, whale/on-chain/social feeds, and the `calculateAdvanced*`/
`calculateQuantum*`/`calculateMultiLayer*` service methods — **none exist**, and
per the task's rule the panel must not advertise them. Delete those tabs rather
than build them.

---

## 8. ربات‌های گرید (Grid Bots)

**Class:** `app/Filament/Resources/BotConfigResource.php` (586 lines) · **Model:**
`BotConfig` (`bot_configs`) · **Pages:** `ListBotConfigs` / `CreateBotConfig` /
`EditBotConfig`.

**Data source:** real CRUD over `bot_configs`. The **Start** row action calls the
real engine: `app(TradingEngineService::class)->initializeGrid($record)`
(`BotConfigResource.php:460-461`) and flips `is_active` on success (`:466`).
**Stop** sets `is_active=false` (`:503`). Edit/Delete/bulk-delete are standard.

**Verdict: REAL.** This is the legitimate control surface for System A — the
engine the 329 tests cover. Creating a bot here is also the **only** way to make
Dashboard / Bot Monitoring / Bot Intelligence show anything.

**Recommendation: KEEP.** This is the backbone. Backend dependency: none — it
already drives `TradingEngineService`.

---

## 9. Notes (گفتمان / یادداشت‌ها)

**Class:** `app/Filament/Pages/Notes.php` · **View:**
`resources/views/filament/pages/notes.blade.php`.

**Data source:** a personal notes tool. Data is stored as JSON on the local disk:
`Storage::disk('local')->put('notes/user_notes.json', …)` (`Notes.php:390`),
loaded from the same file (`:378-386`). No database, no model, no trading table.

**Is it part of the trading domain?** No. It has categories like "trading /
strategy / analysis" but stores free-form user notes in a flat JSON file
(`storage/app/notes/user_notes.json`) with no link to any bot, run, order, or
trade. It is an unrelated personal-notes/scratchpad feature.

**Verdict: REAL but off-domain** — it works, but has nothing to do with the bot.

**Recommendation: REMOVE** from the trading panel (or move to a clearly separate
"personal" area). It's not misleading like the calculator, but it doesn't belong
in an admin panel for a trading engine, and its file-based store isn't
multi-user/durable.

---

## 10. Duplicate / overlapping pages

**Dashboard vs Bot Monitoring vs Bot Intelligence** substantially overlap — all
three are "status of the bot(s)" views over the same System-A tables:

| Concern | Dashboard (`BotStatusWidget`) | Bot Monitoring | Bot Intelligence |
|---|---|---|---|
| active bots / capital | ✅ (`BotStatusWidget:20-29`) | ✅ (`:26,153`) | ✅ (`:107,113`) |
| profit / win-rate | ✅ (`:33-44`) | ✅ (`:100-110`) | ✅ (`:91-99`) |
| open orders | count only (`:41`) | ✅ list (`:32-36`) | ✅ list (`:272-292`) |
| completed trades | count/sum | ✅ (`:45`) | ✅ pairs (`:297-317`) |
| activity-log cycles | ✗ | ✅ (`:114-120`) | ✅ timeline (`:479-503`) |
| grid health/drift/concentration | ✗ | ✗ | ✅ (but reads wrong `side` col, §6) |

Recommendation: collapse to **one** dashboard (headline tiles + 30-day chart)
plus **one** detailed per-bot page. Bot Monitoring and Bot Intelligence are near
duplicates; keep the better-implemented queries (Bot Monitoring uses the correct
`type` column) and drop the other.

---

## 11. Orphaned / unregistered code (not in the sidebar)

**Five Livewire components that nothing renders** (no `@livewire`/`<livewire:>`
tag in any Blade, no route registration — verified by grep over
`resources/views` and `routes/`):
- `app/Livewire/GridStatsCards.php` (+ `grid-stats-cards.blade.php`)
- `app/Livewire/GridLevelsTable.php` (+ `grid-levels-table.blade.php`)
- `app/Livewire/GridLevelsChart.php`
- `app/Livewire/GridCalculatorChart.php`
- `app/Livewire/GridCalculatorAdvanced.php` (+ `grid-calculator-advanced.blade.php`)

These appear to be an earlier calculator/stat implementation, now dead. Note
`GridCalculatorService.php:104` even carries a comment about a stray positional
arg from `GridCalculatorAdvanced`, confirming it once called the real service —
but it is no longer wired to anything. **Recommendation: REMOVE** (dead code) —
or, if the "honest calculator" rebuild (§7) wants a starting point,
`GridCalculatorAdvanced` is the one that historically used the real service.

**Dev/utility routes not in the sidebar** (expected, but worth knowing):
`routes/web.php` registers `/api/health`, `/api/market-status`, `/status`,
`/robots.txt`, `/grid/export/{key}`, and a block of `local`-only `/test-*`
routes (`:806-1061`). These are API/util endpoints, not admin pages; not
orphaned in the problematic sense, but the `/test-*` set should be confirmed as
`local`-gated before any production overhaul.

**Widgets** `BotStatusWidget`, `PerformanceChartWidget`, `WebSocketHealthWidget`,
and `RunStats` are auto-discovered; the first three render on the Dashboard, and
`RunStats` renders on the Grid Run view page. None are orphaned.

---

## 12. Resources vs models — coverage

Models: `User`, `BotConfig`, `GridOrder`, `CompletedTrade`, `BotActivityLog`,
`GridRun`, `GridRunOrder`, `GridEvent`.

Filament **Resources**: only `BotConfigResource` (`BotConfig`) and
`GridRunResource` (`GridRun`). Both are fully built and real — **no half-built
resource is wired into the sidebar.**

Models with **no** dedicated resource: `GridOrder`, `CompletedTrade`,
`BotActivityLog`, `GridRunOrder`, `GridEvent`, `User`. These are intentionally
surfaced through pages/relation managers rather than standalone resources:
- `GridOrder` / `CompletedTrade` / `BotActivityLog` → Bot Monitoring & Bot
  Intelligence pages.
- `GridRunOrder` / `GridEvent` → `GridRunResource` relation managers
  (`GridRunResource.php:102-108`).

So there is **no orphaned Resource** and **no sidebar item pointing at a
half-built Resource**. The gap is the reverse: several status *pages* depend on
`bot_configs` being populated, which only the *Grid Bots* form does.

---

## 13. Recommendation roll-up + backend dependencies

| Page | Recommendation | Backend work required before/with it |
|---|---|---|
| Grid Bots (`BotConfigResource`) | **KEEP** | none — already drives `TradingEngineService` |
| Grid Runs (`GridRunResource`) | **KEEP** (or retire with System B) | none; optionally populate `grid_runs.bot_id` to link to bots |
| Connection Test | **KEEP** | none; de-fake the sim-mode latency line |
| Dashboard | **COMPLETE** | none new — decide System-A-vs-B source of truth; fix `$`→IRT label |
| Bot Monitoring | **COMPLETE / MERGE** | none — reads engine tables; needs a populated active bot |
| Bot Intelligence | **COMPLETE or REMOVE** | none — fix `side`→`type` (§6); resolve overlap |
| Notes | **REMOVE** (off-domain) | none |
| Grid Calculator نسل آینده | **REMOVE** shell tabs; rebuild small | reuse existing `GridCalculatorService`; do **not** build the advertised neural/quantum/sentiment/`calculateAdvanced*` features — **none exist** and the panel must not claim capabilities the engine lacks |
| 5 orphan Livewire components | **REMOVE** (dead) | none |

**Overarching backend decision (blocks the overhaul):** reconcile the two bot
systems (§0). Either make `bot_configs` the single source of truth and retire the
`grid:run` CLI recorder, or link the two by populating `grid_runs.bot_id`. Until
then, the "status" pages will keep reading zero while `grid:run` output piles up
in a table the dashboard never queries.

---

*End of audit. No production code was modified.*
