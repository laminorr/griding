# Phase 13 — Investigation: Test Coverage for Financial & Grid Logic

**Status:** investigation only. No production code, migrations, config, or resources were
changed. No tests were written. This document is the sole deliverable.

**Scope reminder:** Phases 1–12 are merged. Phase 12 proved the bot talks to the exchange
safely (network layer, retry/rate-limit, `GridOrderExecutor`, the pair-order path in
`CheckTradesJob`, `SubmissionReconciler`). Nothing yet proves the bot *computes* correctly.
Phase 13 must close roadmap item #40: real coverage for the FINANCIAL and GRID logic.

The current suite is 8 test files under `tests/Feature` + `tests/Unit` (the "57 tests"
figure). `vendor/` is not installed in this investigation container, so the suite was not
re-executed here; all coverage claims below are from reading the test sources, not a run.

---

## 1. Coverage matrix

Legend — **Testability:** `unit` = pure, no DB/network/clock; `DB` = needs a database;
`refactor` = not testable as written without a code change (static `app()`/`config()`/`now()`
reads, un-injected deps, `sleep`). **Risk if wrong:** blast radius on real money.

| Component | Current coverage | Testability | Risk |
|---|---|---|---|
| `Support\Money` (all bcmath ops, `normalize`, `alignToTick`, `round`) | **none** | unit | **High** — every price/qty/profit flows through it |
| `GridPlanner::plan` (layout, modes, split, sizing, min-notional, tick) | **none** | unit (fake `MarketData`) | **High** |
| `GridCalculatorService::calculateGridLevels` + `generate{Log,Arith,Geo}Grid` | **none** | unit (mock `NobitexService` ctor dep) | Medium |
| `GridCalculatorService::calculateOrderSize` / `optimizeOrderSize` / min-value | **none** | refactor (`Cache::remember` + live price) | Medium |
| `GridCalculatorService::calculateExpectedProfit` (dry-run estimator) | **none** | refactor (calls Nobitex) | Low (reporting only) |
| `CompletedTrade::createFromOrders` (**the only booking path**) | **none** | DB | **High** |
| `CheckTradesJob::recordCompletedTrade` (float shadow + delegates) | **none** | DB + refactor | Medium |
| `CheckTradesJob::createCompletedTradeIfPaired` (both-legs guard, invariant 5/6/7) | **none** | DB + refactor | **High** |
| `CheckTradesJob::createPairOrderLocked` (pairing, invariant 3/4/7) | **partial** — `CheckTradesPairOrderTest` | DB + reflection | **High** |
| `KillSwitchService` (stop-loss, max-drawdown, already-stopped) | **none** | DB (inject `MarketDataLayer` — good) | **High** |
| `GridOrderObserver` (`open_cycles_count`, `capital_locked_irt`) | **none** | DB | Medium |
| `TradingEngineService::initializeGrid` (full path) | **none** | refactor (many deps + `sleep`) | **High** |
| `GridOrder` / `CompletedTrade` / `BotConfig` decimal mutators | **indirect** via executor tests | DB (MySQL for fidelity) | **High** |

Already-covered-elsewhere (Phase 12, out of Phase 13 scope): `GridOrderExecutorTest`,
`SubmissionReconcilerTest`, `Nobitex/*` (retry, rate-limit, timeout, client-ref).

---

## 2. Findings per target

### 2.1 `App\Services\GridPlanner::plan`

**What it does.** Pure planning/dry-run. Given a symbol, reference price, level count, step %,
mode, budget, optional `fixedQty`, `tick`, and `presetBaseQty`, it returns an array plan:
per-level `items` (side/price/quantity/notional/below_min), plus reporting aggregates
(`estimated_notional`, `estimated_fee_irt`, `collapsed_levels`, `below_min_orders`).
It does **not** place orders or touch the DB.

**Inputs/outputs.** Inputs listed above; output is the `$plan` array. Price math uses
`Money::mul`/`round` on strings; only the geometric spacing `pow(1±step, i)` is native float
(exponent is integer, base is float — bcmath has no fractional `pow`), with tick-rounding
absorbing residual float noise (`GridPlanner.php:98`, `:108`).

**Invariants it carries.**
- **#1 (N/2 split).** `perSide = intdiv(levels,2)` in `both`, else `levels`
  (`GridPlanner.php:84`). Buys placed below mid (`:90-103`), sells above (`:106-113`).
- **#1 (directional).** `mode='buy'`/`'sell'` skip the other side entirely.
- **`both` requires even levels** — throws on odd (`:73-75`), rather than silently dropping a
  level.
- **#8 (min order value).** `below_min` flag per item vs `min_order_value_irt`
  (`:186-187`); note this only *reports* — `GridPlanner` itself does not drop below-min items
  (`GridOrderSync` does, per `placeGridOrders` comments).
- **Tick alignment.** buys floor to tick, sells ceil (`roundToTick`, `:227-238`).
- **Balance-aware sizing (Phase 11 Step 5).** `presetBaseQty` split evenly across sell levels
  (`:146-160`, applied `:165-170`); a preset that rounds to zero per level reverts to naive.

**Current coverage.** None.

**What testing requires.** `plan()` takes `MarketData` via constructor
(`GridPlanner.php:12`) — a one-method fake (`getLastPrice(): int`) removes the only external
dependency, and passing `$lastPrice` explicitly avoids even that. **Pure unit, no DB.** The
only impurities are direct `config()` reads (`:49,52,58,59`), `now()->timestamp` (`:219`), and
`Log::channel('trading')` (`:222`). `config()` is set via `config([...])` in-test; `now()` via
`Carbon::setTestNow()`; `Log` needs the app bootstrapped but not a DB — so this is a
`TestCase`-with-app unit test, not a `RefreshDatabase` test.

**Depends on:** config (tick/min-notional/fee-bps/qty-decimals), `MarketData` (only if
`$lastPrice` omitted), clock (`now()` for the reporting `ts` field only). No DB, no network.

### 2.2 `App\Support\Money`

**What it does.** Stateless bcmath wrapper: `add/sub/mul/div`, `compare/min/max`, sign
predicates, `abs`, `normalize`, `round`, `alignToTick`, `irtToBase`/`baseToIrt`, `trimZeros`.
Default scale 20.

**The high-risk paths (item history: 32-bit overflow / silent truncation):**
- `normalize(float)` uses `sprintf('%.20F', $v)` to force fixed-point so bcmath never sees
  `1.0E-7` (`Money.php:265`) — the scientific-notation bug. Rejects `NAN/INF`, `null`, `bool`,
  non-scalars. **This is the single most important pure function to pin.**
- `div` throws `DivisionByZeroError` on a zero divisor instead of returning `"0"`
  (`:106-112`).
- `round` mixes native `round((float)...)` inside a bcmath expression (`:288`) — a subtle
  spot worth explicit large-value tests (it casts to float mid-computation).
- `alignToTick` also casts to float for `floor/ceil/round` (`:303-313`) — same caveat.
- `trimZeros`, `abs` are pure string ops.

**~20-digit IRT behaviour.** `add/sub/mul/div/compare` operate on strings at scale 20 and
never cast, so a 20-digit IRT string is exact. The float-casting spots (`round`, `alignToTick`)
are the ones to probe with ~20-digit inputs.

**Current coverage.** None. **Testability: pure unit, no app even needed** (no Laravel
dependency by design, `Money.php:37`). Highest value-per-effort in the whole phase.

**Depends on:** nothing (ext-bcmath only).

### 2.3 `GridCalculatorService::calculateGridLevels`

**What it does.** Given `centerPrice`, `spacing %`, `levels`, `algorithm`
(`logarithmic`|`arithmetic`|`geometric`), and `mode`, returns enriched grid levels plus
analysis/performance metadata. The three generators:
- `generateLogarithmicGrid` — `price = center * pow(1±spacing/100, i)` (`:462-490`).
- `generateArithmeticGrid` — `price = center ± center*(spacing/100)*i` (`:495-525`).
- `generateGeometricGrid` — `price = center * ratio^±i`, `ratio = 1+spacing/100`
  (`:530-560`).

**Per-side split (#1).** `generateGridLevels` (`:423-451`): `both` → `intdiv(levels,2)` each
side; `buy` → all buy; `sell` → all sell (Phase 11 Step 6), mirroring `GridPlanner`.

**Validation.** `validateGridInputs` (`:379-396`): spacing 0.5–10, levels 4–20 and **even**,
positive center. Note this even/range guard is **stricter** than `GridPlanner`'s (which only
requires even in `both` mode) — a discrepancy worth a test to document, not necessarily fix.

**Everything else in this 1935-line class is float, and is reporting/estimation only** —
`enhanceGridLevels`, `analyzeGridQuality`, `calculateGridPerformance`, all the risk/ROI/
probability helpers. They never persist financial records.

**Current coverage.** None.

**What testing requires.** Constructor needs `NobitexService` (`:46-49`), but
`calculateGridLevels` and the generators never call it — a Mockery mock passed to the
constructor suffices. `calculateGridLevels` is **pure given its args** (no DB, no clock; a
failure only logs). **Unit-testable.** `calculateOrderSize` is different — it calls
`getCurrentPriceWithValidation` → `Cache::remember` → `nobitexService->getCurrentPrice`
(`:1028-1041`), so it needs the price mocked (refactor-adjacent, but mockable via the ctor).

**Depends on:** `NobitexService` (only `calculateOrderSize`/`calculateExpectedProfit`/
`quickMarketAnalysis`, not `calculateGridLevels`), `Cache` (price memoization), config
(min-notional, qty-decimals).

### 2.4 Profit logic — reachability map (**read this carefully**)

The roadmap named three implementations. **Today only one persists a `CompletedTrade`; the
second is a logging-only shadow; the third no longer exists.**

| Implementation | Reachable? | Persists? | Arithmetic | Fee source |
|---|---|---|---|---|
| `CompletedTrade::createFromOrders` | **Yes** | **Yes — the only writer** | **BCMath/`Money`** | `botConfig->fee_bps ?? config('trading.exchange.fee_bps', 35)`, `feeRate = bps/10000` |
| `CheckTradesJob::recordCompletedTrade` | Yes | No (delegates) | **float** (log only) | same `fee_bps` source (`CheckTradesJob.php:948`) |
| `CheckTradesJob::createCompletedTradeIfPaired` `$profit` | Yes | No | **float** (log only, `:642`) | none (gross only) |
| `TradingEngineService::createCompletedTrade` | **No — does not exist** | — | — | — |
| `GridCalculatorService::calculateExpectedProfit` | Yes (dry-run) | No | **float** | hardcoded `NOBITEX_FEE_RATE = 0.25%` + `0.1%` slippage |

**Details.**

- **`CompletedTrade::createFromOrders` (`CompletedTrade.php:324-423`) is the live booking
  path.** Every value it persists is computed with `Money` on decimal strings:
  - `amount = Money::min(buyAmount, sellAmount)` — books on the **matched** quantity, warns on
    unequal legs (`:357-369`). (Task's "one uses `min(filled_amount)`" — it actually uses
    `min` of the two orders' `amount`, not `filled_amount`.)
  - `gross_profit = (sellPrice - buyPrice) * amount`.
  - `totalFee = feeRate * (buyNotional + sellNotional)`.
  - `profit` **and** `net_profit` columns both = `netProfit = gross - totalFee`.
  - `profit_percentage = gross / buyNotional * 100` (guards zero notional → `"0"`).
  So `profit` is **net**, while `profit_percentage` is computed from **gross** — a real,
  documentable asymmetry (task's "profit vs profit_percentage from net vs gross").

- **`recordCompletedTrade` (`CheckTradesJob.php:936-1012`)** computes `grossProfit`,
  `totalFee`, `netProfit` in **native float** (`:950-952`) purely to log them, then calls
  `CompletedTrade::createFromOrders` for the real persistence (`:971`). So the numbers a user
  sees in logs are float; the numbers in the DB are BCMath. They can disagree at the last
  digits — a legitimate test target (assert the persisted row matches an exact bcmath
  expectation, independent of the log line).

- **`createCompletedTradeIfPaired` (`:591-654`)** is the gatekeeper for invariants 5/6/7 (see
  §2.5). Its own `$profit` (`:642`) is float and only feeds `logOrderPaired`.

- **`calculateExpectedProfit`** is a **dry-run estimator** (never writes a trade). It is the
  only place with a **hardcoded** fee (`NOBITEX_FEE_RATE = 0.25%`, `GridCalculatorService.php:26`)
  — which *disagrees* with the live 0.35% (`35 bps`) default. Worth a test that documents the
  divergence; do not reconcile in Phase 13.

- The task referenced `calculateTradeFee` — **no such method exists**; the estimator's helper
  is `calculateTradingFees` (`:716-735`), float, on the reporting path only.

**Float-vs-BCMath summary:** the **persisted** profit math is fully BCMath (Phase 10 done).
The remaining float lives in **logging shadows** (`recordCompletedTrade`,
`createCompletedTradeIfPaired`) and the **dry-run estimator** (`calculateExpectedProfit`,
most of `GridCalculatorService`). None of the float paths write a financial record.

**Coverage:** none. **Testability:** `createFromOrders` needs DB (`self::create`) plus a
`completed_trades` table (not currently in the trait) and `cache()`/`now()` control.

### 2.5 Pair-finding & invariant 7 (no double pairing / double booking)

**Where.** `CheckTradesJob::createPairOrderLocked` (`:700-931`) selects nothing by
nearest-price anymore — pairing is via the **stable bidirectional `paired_order_id` link**.
`findPairOrder` (named in the task) **no longer exists**; `TradingEngineService::findPairOrder`
is gone too.

**The guard stack for invariant 7:**
1. `processBot` only selects fills `whereNull('paired_order_id')` (`:120-123`) — a linked fill
   is never re-picked.
2. `Cache::lock("pair-order:{id}")` (`:683`) — per-fill mutex across workers.
3. Inside a transaction, `lockForUpdate` re-read + `paired_order_id !== null` short-circuit
   (`:768-777`) — the concurrency guard proven by `CheckTradesPairOrderTest` test #6.
4. `client_order_id` dedup guard, including `submission_unknown` (`:739-752`).
5. Booking side: `createCompletedTradeIfPaired` books only when **both** legs are `filled`
   (`:615-618`), legs are **opposite** types (`:621-624`), and an `alreadyBooked` existence
   check on `(buy_order_id, sell_order_id)` (`:633-640`).

**Are the guards sufficient?** For invariant 7, yes in the modelled paths — and #3/#4 already
have Phase-12 tests. What is **not** covered: the *booking* guards (5) — both-legs-filled
deferral (invariant 5), opposite-type requirement, `alreadyBooked` idempotence — and the
end-to-end "one fill → exactly one opposite order → exactly one CompletedTrade" (invariants
3/4/7 together). Those are the Phase-13 gaps.

**Invariant 6 (sell > buy in every completed cycle).** Not asserted anywhere. It holds *by
construction*: a filled buy spawns a sell at `price*(1+spacing)` (above), a filled sell spawns
a buy at `price*(1-spacing)` (below) (`createPairOrderLocked.php:709-712`). So both event
orders yield sell > buy. But there is **no runtime assertion**, making it a clean invariant to
pin with a test (both buy-then-sell and sell-then-buy orderings).

**Coverage:** pairing partial (Phase 12); booking none.

### 2.6 `KillSwitchService`

**What it does.** Evaluates two thresholds on a bot and trips (sets `is_active=false`,
`stop_reason`, `stopped_at`, saves) if breached:
- **stop-loss** (`:80-121`): `abs((price - grid_center_price)/grid_center_price*100)` vs
  `stop_loss_percent`. Anchor is the **stable** `grid_center_price`, not the drifting
  `center_price`. Returns null (no trip) if no anchor or price unavailable — a transient
  market-data failure must not trip.
- **max-drawdown** (`:128-168`): sums `net_profit` of losing `completedTrades` via bcmath,
  expresses as % of `total_capital`, compares to `max_drawdown_percent`.

**Already-stopped behaviour.** `trip` (`:184-203`): if `is_active` is already false it still
returns `triggered=true` (so `initializeGrid` aborts a re-run) but skips the redundant save.
One-way — never auto-recovers.

**Coverage:** none. **Testability:** best-shaped of the DB targets — `MarketDataLayer` is
**constructor-injected** (`:32`), so the price is trivially faked. Needs DB for `BotConfig` +
`completed_trades` (for drawdown) and `Carbon::setTestNow()` for `stopped_at`. All arithmetic
is `Money` (exact-assertable).

**Depends on:** `MarketDataLayer` (injected), DB (`BotConfig`, `completedTrades`), clock.

### 2.7 `GridOrderObserver`

**What it does.** On every `GridOrder` save/delete, recomputes for the owning bot
(`:97-144`):
- `open_cycles_count` = count of `role='cycle_exit'` AND `status='placed'` (both sides).
- `capital_locked_irt` = Σ over **sell-side** open cycles of the paired **buy's**
  `price * amount` (bcmath), floored to integer IRT scale (`:136`).

**Correctness questions to pin (invariant 9).** After a fill (cycle_exit sell → filled) the
count must drop and locked capital release; after a delete the recompute must run against the
post-delete state. In a one-directional market (cycles stay open) the accounting must not
drift. Note the "capital locked = buy-side open cycles only" rule (a waiting cycle_exit *buy*
counts toward the count but locks nothing, `:26-35`) — a subtle, high-value test case.

**Coverage:** none. **Testability:** DB-only, but note the observer is **registered globally**
(`AppServiceProvider.php:45`), so it fires on every `GridOrder::create` in *any* DB test —
tests must build the `bot_configs` columns it writes (`open_cycles_count`,
`capital_locked_irt`) or every save throws. The current trait includes `grid_orders` and
`bot_configs` but **not** those two columns — **the trait needs extending** (see §Q2).

**Depends on:** DB only (no clock, no network).

### 2.8 `TradingEngineService::initializeGrid`

**What it does.** The full live init path (`:54-229`): kill-switch gate → preflight → market
analysis → center price → `calculateGridLevels` → `calculateOrderSize` → quote-balance check →
cleanup existing → `placeGridOrders` (via `GridPlanner`→`GridOrderSync`→`GridOrderExecutor`) →
health evaluation → `BotConfig` update.

**What must be mocked to test it.** Seven constructor deps (`NobitexService`,
`GridCalculatorService`, `BotActivityLogger`, `GridPlanner`, `GridOrderSync`,
`GridOrderExecutor`, `KillSwitchService`) — all injected (good). But the path also:
- calls `getBalances`/`getCurrentPrice`/`healthCheck` on the live service (mockable),
- `cleanupExistingOrders` calls **`sleep(1)` per existing order** (`:544`) — real wall-clock,
- reads `auth()` (`:275`), `config('services.nobitex.*')` (`:247`), `now()` repeatedly.

Testing the whole method end-to-end is a **refactor-first** target. However
`evaluateInitializationHealth` (`:313-359`), `resolveBotMode` (`:369-380`), and
`computePresetBaseQty` (`:799-881`) are **pure private helpers** (arrays/strings + `Money`) and
are unit-testable in isolation via reflection today — high value, low cost, and they carry real
logic (the 80% + per-side health rule, and the balance-aware threshold rules).

**Coverage:** none. **Testability:** full method = refactor; the three helpers = unit
(reflection).

---

## 3. Answers to the required questions

### Q1 — Which targets are pure enough for unit tests with **no** database?

- **`Money`** — every method. No app, no DB. *(highest priority)*
- **`GridPlanner::plan`** — with `$lastPrice` passed (or a one-method `MarketData` fake); needs
  the app booted for `config()`/`Log`, but **no DB**.
- **`GridCalculatorService::calculateGridLevels`** and the three `generate*Grid` methods — with
  a Mockery `NobitexService` in the constructor; pure given args.
- **`TradingEngineService` helpers** `evaluateInitializationHealth`, `resolveBotMode`,
  `computePresetBaseQty` — via reflection, no DB.
- **`GridOrder::buildClientOrderId`** — static, pure.

### Q2 — Which require DB, which tables, and does `BuildsGridSchema` already build them?

DB-required: `CompletedTrade::createFromOrders`, `CheckTradesJob` pairing/booking,
`KillSwitchService`, `GridOrderObserver`, `TradingEngineService::initializeGrid` (full).

Tables needed: `bot_configs`, `grid_orders`, `bot_activity_logs` (built today), **plus
`completed_trades` (NOT built)**.

**The trait needs extending in two ways:**
1. **Add a `completed_trades` table** — required by `createFromOrders`, the max-drawdown branch
   of `KillSwitchService`, and any booking test. Absent today.
2. **Add columns to `bot_configs`** that the financial paths read/write but the trait omits:
   `fee_bps`, `total_capital`, `active_capital_percent` (present), `center_price`,
   `grid_center_price`, `grid_levels`, `stop_loss_percent`, `max_drawdown_percent`,
   `open_cycles_count`, `capital_locked_irt`, `started_at`, `stop_reason`, `init_status`,
   `last_check_at`, `last_rebalance_at`. The **observer fires on every `grid_orders` insert**
   and writes `open_cycles_count`/`capital_locked_irt`, so *any* DB test that creates a
   `GridOrder` needs those two columns present or it throws.

### Q3 — The sqlite debt: (a) extend the trait, (b) driver-aware migrations, or (c) MySQL test DB?

**Recommendation: (c) a dedicated MySQL test database for the financial/DB-backed tests, with
pure-unit tests (Money, GridPlanner, calculator algorithms) written first and needing no DB at
all. Reject (b). Keep (a) only as-is for the existing structural Phase-12 tests.**

Reasoning:

- **Faithfulness is the whole point here.** The financial correctness this phase must prove
  depends on production column semantics that **sqlite cannot reproduce**:
  - `DECIMAL(20,0)` IRT: sqlite has no true DECIMAL — a column with NUMERIC affinity stores
    values as 64-bit INTEGER or REAL. A full 20-digit IRT value exceeds the 64-bit integer
    ceiling (~9.2×10¹⁸) and falls back to REAL (float) — **reintroducing exactly the
    truncation/precision loss the Phase-10 BCMath migration removed.** A `Money` overflow test
    that asserts a ~20-digit value round-trips would *pass falsely* or behave differently than
    production MySQL. (Real BTCIRT prices are ~10¹¹ / 11 digits, which *do* fit — so ordinary
    tests survive on sqlite, but the overflow-regression tests that justify `Money`'s existence
    do not.)
  - `ENUM` status and `MODIFY COLUMN`: the two migrations that already break sqlite
    (`2025_11_07_000001`, `2026_06_25_000001`).
  - The **string mutators** on `GridOrder`/`CompletedTrade`/`BotConfig` exist specifically to
    force `PARAM_STR` binding against a driver that would otherwise truncate — a behaviour that
    only manifests against a real MySQL/PDO path.
- **(b) is the worst option:** it means editing *production* migration files to be
  driver-aware (risk to prod DDL) in service of a test DB that *still* can't represent
  `DECIMAL(20,0)` faithfully. High maintenance, low fidelity, prod-risk.
- **(a) extend the trait** is a fine *stopgap* and is already the Phase-12 pattern, but it
  hand-maintains a second schema definition that drifts from the real migrations, and it runs
  on sqlite — so it must carry an explicit "do not assert 20-digit precision here" caveat. Good
  enough for structural/logic tests (pairing linkage, observer counts with realistic prices,
  status transitions); **not** trustworthy for the decimal-overflow and mutator-fidelity tests.
- **(c) MySQL test DB** costs CI/dev infra (a MySQL service, a `.env.testing`, running the real
  migrations) but is the only option that reproduces `DECIMAL(20,0)`, `ENUM`, and the
  `PARAM_STR` mutator behaviour — i.e. the only place the financial tests are *worth trusting*.

**Concretely:** write the pure-unit tier now (zero infra, most of the value). For DB-backed
financial tests, stand up MySQL and run the real migrations (this also retires the migration
sqlite-incompatibility debt for tests). Where a DB test does not assert on big-decimal
precision, the extended trait remains acceptable to avoid blocking on infra.

### Q4 — Existing factories/seeders for `BotConfig`, `GridOrder`, `CompletedTrade`?

Only `database/factories/UserFactory.php` exists. **None** for the trading models. Minimum set
needed:
- `BotConfigFactory` — sensible BTCIRT defaults, `simulation`/`is_active` states, and states
  for kill-switch fields (`grid_center_price`, `stop_loss_percent`, `max_drawdown_percent`,
  `total_capital`, `fee_bps`).
- `GridOrderFactory` — states for `buy`/`sell` × `placed`/`filled`/`pending`/`cancelled`/
  `submission_unknown`/`partially_filled`, `role` (`initial_grid`/`cycle_exit`), and a helper
  to link a pair (`paired_order_id` both ways). Must respect the `price` string mutator and the
  `saving()` positivity/length guard (`GridOrder.php:105-115`).
- `CompletedTradeFactory` — for `KillSwitchService` drawdown tests and analytics; needs
  `net_profit` states (winning/losing) and a `fromOrders(buy, sell)` convenience.

Factories depend on schema, so they only help once the DB question (Q3) is settled — with
MySQL they run the real migrations; with the trait they need the extensions in Q2.

### Q5 — Currently UNTESTABLE without refactoring (file:line)

- `CheckTradesJob` resolves collaborators via `app()` **inside methods**, not the constructor:
  `app(BotActivityLogger::class)` (`CheckTradesJob.php:91`, `:230`, `:399`, `:650`, `:702`,
  `:938`), `app(MarketDataLayer::class)` (`:197`), `app(NobitexService::class)` (`:260`,
  `:859`). Testable today only by `$this->app->instance(...)` swaps + reflection on private
  methods (the established `CheckTradesPairOrderTest` pattern), which works but is brittle;
  constructor injection would make it clean.
- `TradingEngineService::cleanupExistingOrders` — `sleep(1)` per order (`:544`): real-time in
  tests.
- `TradingEngineService::logForceStartOverride` — `auth()->check()/id()` (`:275`).
- `TradingEngineService` — direct `config('services.nobitex.*')` (`:247`).
- `GridCalculatorService::getCurrentPriceWithValidation` — `Cache::remember` wrapping a live
  price (`:1028-1041`); constructor hard-requires `NobitexService`.
- `CompletedTrade::createFromOrders` — `cache('btc_price')` (`:418`), `now()` (`:419`),
  `config('trading.exchange.fee_bps')` (`:380`), and `detectMarketTrend` cache reads
  (`:431-432`); plus `self::create` (DB).
- `GridPlanner::plan` — direct `config()` (`:49,52,58,59`), `now()` (`:219`),
  `Log::channel('trading')` (`:222`). (Low friction — all controllable in-test — but not
  *pure*.)
- `CheckTradesJob::createPairOrderLocked` — SIM id uses `uniqid()`/`time()` (`:836`);
  non-deterministic but only asserted by prefix today.

### Q6 — Where does non-determinism live, and how to control it?

- **Clock (`now()` / `Carbon`)** — `GridPlanner` (`ts`), `CheckTradesJob`
  (`filled_at`/`last_fill_at`/`last_check_at`), `TradingEngineService` (`started_at`/
  `last_rebalance_at`), `KillSwitchService::trip` (`stopped_at`), `CompletedTrade`
  (`execution_time_seconds` = `sellOrder->updated_at->diffInSeconds(buyOrder->created_at)`,
  and `market_conditions.timestamp`). **Control with `Carbon::setTestNow()`**; for
  `execution_time_seconds`, set explicit `created_at`/`updated_at` on the order fixtures.
- **Live price** — `MarketData::getLastPrice` (`GridPlanner`, `KillSwitchService`,
  `checkSimulatedOrders`) and `NobitexService::getCurrentPrice` (`GridCalculatorService`,
  `TradingEngineService`). **Control by injecting a fake** (`KillSwitch`/`GridPlanner` take the
  contract in the constructor; `CheckTradesJob` via `$this->app->instance`).
- **Cache** — `cache('btc_price')`/`btc_price_1h_ago` in `CompletedTrade`,
  `validated_price_*` in `GridCalculatorService`. Tests already use the `array` cache store
  (`phpunit.xml`); **seed keys explicitly** or assert independence of them.
- **Randomness** — `uniqid()`/`time()` SIM ids (`createPairOrderLocked`). **Assert on the
  `SIM-` prefix / row existence, not the exact id** (current tests already do this).
- **HTTP / exchange** — controlled with `Http::fake()` and/or a Mockery `NobitexService`
  (established in Phase 12 tests).
- **Real time cost** — `sleep(1)` (`cleanupExistingOrders`), `usleep(100000)`
  (`checkOrdersStatus`): not correctness non-determinism but they make DB tests slow; avoid by
  testing helpers below those methods, or by refactoring the sleeps behind an injectable
  delay.

---

## 4. Proposed Phase 13 step breakdown

Small, independent, each independently mergeable and testable, ordered so infra cost rises only
when the value requires it. **Nothing here is implemented in this session.**

**Step 1 — `Money` unit tests (no DB, no app).**
Pin `normalize` (scientific-notation floats, NAN/INF/null/bool/non-scalar rejection),
`div` zero-divisor throw, `add/sub/mul/compare/min/max` at ~20-digit IRT scale, `round` and
`alignToTick` large-value behaviour (the float-cast spots), `trimZeros`/`abs`. Highest value,
zero infra. *(Depends on: nothing.)*

**Step 2 — `GridPlanner::plan` unit tests (app, no DB).**
Invariant #1 (both split N/2, buy/sell directional, even-levels throw), tick floor/ceil,
`below_min` flagging (#8), `collapsed_levels` de-dup, and `presetBaseQty` sell sizing (Phase 11
Step 5) including the rounds-to-zero revert. Pass `$lastPrice` to avoid `MarketData`; set
`config()`/`setTestNow()`. *(Depends on: Step 1 conventions only.)*

**Step 3 — `GridCalculatorService::calculateGridLevels` unit tests (app, no DB).**
The three algorithms (log/arith/geo) produce correct level counts, sides, and monotonic prices;
the per-side split matches `GridPlanner` in `both`/`buy`/`sell`; `validateGridInputs`
range/even guards. Mockery `NobitexService` in the constructor. Also document the stricter
even/range guard vs `GridPlanner`. *(Depends on: Step 1.)*

**Step 4 — `TradingEngineService` pure-helper unit tests (app, no DB, reflection).**
`evaluateInitializationHealth` (0-success→failed; ≥80% + per-side minimum→running; else
partial), `resolveBotMode` (legacy/null→both), `computePresetBaseQty` (null triggers: buy-mode,
non-positive mid/budget, below-threshold, exceeds-budget; engage case). *(Depends on: Step 1.)*

**Step 5 — Extend `BuildsGridSchema` + add model factories.**
Add the `completed_trades` table and the missing `bot_configs` columns (Q2). Add
`BotConfigFactory`, `GridOrderFactory` (with a pair-link helper), `CompletedTradeFactory`.
No behaviour tested yet — this is the enabling step for 6–9, and it keeps them small.
*(Depends on: nothing in prior steps; unblocks all DB steps.)*

**Step 6 — Decide + wire the MySQL test path (Q3).**
Add `.env.testing` + a CI MySQL service and a base test case that runs the *real* migrations,
used by the precision-sensitive tests. Prove it with one `DECIMAL(20,0)` round-trip test
(a ~20-digit IRT value stored and read back exactly through the string mutator) that
*cannot* pass on sqlite. Structural DB tests may still use the Step-5 trait. *(Depends on:
Step 5 for factories.)*

**Step 7 — `CompletedTrade::createFromOrders` booking tests (DB, MySQL).**
Exact bcmath expectations for `gross_profit`, `fee` (from `fee_bps`), `profit`/`net_profit`
(net), `profit_percentage` (from gross), `amount = min(legs)` + unequal-leg warning path;
zero-notional guard. Assert `profit` is net while `profit_percentage` is gross-derived
(document the asymmetry, don't fix). *(Depends on: Steps 5–6.)*

**Step 8 — `CheckTradesJob` booking-guard tests (DB).**
Invariant #5 (partner not filled → no `CompletedTrade`, open cycle only), #6 (sell > buy for
both buy-then-sell and sell-then-buy orderings), #7 booking side (`alreadyBooked` idempotence;
opposite-type requirement). Reuses the reflection pattern from `CheckTradesPairOrderTest`.
*(Depends on: Steps 5–7.)*

**Step 9 — `KillSwitchService` tests (DB, injected `MarketDataLayer`).**
Stop-loss trip vs no-trip around the threshold using `grid_center_price` anchor; price-
unavailable → no trip; max-drawdown from losing `completed_trades`; already-stopped returns
`triggered=true` without a second save. *(Depends on: Step 5; MySQL not required — no 20-digit
assertions — so trait-backed is acceptable.)*

**Step 10 — `GridOrderObserver` tests (DB).**
Invariant #9: `open_cycles_count` and `capital_locked_irt` after a cycle_exit sell is placed,
after it fills (release), after delete; the buy-side-only capital rule; a one-directional-market
scenario where cycles stay open and accounting stays correct. *(Depends on: Step 5.)*

**Step 11 (optional, later) — refactor `CheckTradesJob` toward constructor injection.**
Only if Steps 8/10 prove too brittle via `app()->instance` + reflection. Pure test-seam
refactor, no behaviour change; keep it separate so the test steps merge independently first.

**Explicitly out of scope for Phase 13** (reporting/estimation float paths that never persist):
`GridCalculatorService::calculateExpectedProfit` and the risk/ROI/probability helpers, and the
float logging shadows in `recordCompletedTrade`/`createCompletedTradeIfPaired`. Note their
existence (and the estimator's hardcoded 0.25% fee vs live 0.35%) but do not test or reconcile
them here.
