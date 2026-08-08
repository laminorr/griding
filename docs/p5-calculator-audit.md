# P5 — Grid Calculation Engine: Read-Only Capability Audit

**Purpose:** Inventory what the grid engine can *actually* compute today, before
building an honest calculator UI in the Filament panel. Every claim below is
backed by source that was read, not by method names.

**Primary target:** `app/Services/GridCalculatorService.php` (confirmed path;
1941 lines; `class GridCalculatorService`, `@version 2.0.0`).

**Method of audit:** static reading only. No calculation code was executed
(container may lack `ext-bcmath`; the calculator itself does not use bcmath — see
§4/§7).

> **Headline finding for the UI team:** Of the eight public methods, only
> **`calculateGridLevels`** and **`assessGridRisk`** are fully pure (inputs →
> output, no I/O). **`calculateExpectedProfit`** is *nearly* pure but attempts
> one exchange call with a safe fallback. Everything else touches the exchange
> and/or cache. Also: this service does **all** its math in **raw PHP floats**
> and **does not use `App\Support\Money`** and **does not tick-align prices** —
> so its level prices will *not* match what the real placement engine
> (`GridPlanner`, which *is* Money+tick based) actually places. See §7.

---

## 1. GridCalculatorService — full public method inventory

Constructor: `__construct(NobitexService $nobitexService)` — stores the injected
`App\Services\NobitexService` as `$this->nobitexService`.

Relevant class constants (used throughout, hard-coded in the class):

| Constant | Value | Notes |
|---|---|---|
| `NOBITEX_MIN_BTC_AMOUNT` | `0.000001` | min crypto qty clamp |
| `NOBITEX_FEE_RATE` | `0.25` | **0.25% per trade — hard-coded; does NOT match config `fee_bps=35` (0.35%). See §5/§7.** |
| `EXCHANGE_SLIPPAGE` | `0.1` | 0.1% assumed slippage |
| `MIN_GRID_LEVELS` / `MAX_GRID_LEVELS` | `4` / `20` | level bounds |
| `MIN_SPACING` / `MAX_SPACING` | `0.5` / `10.0` | spacing % bounds |
| `MIN_CAPITAL_IRT` | `10000000` | 10M IRT floor for `calculateOrderSize` |
| `OPTIMAL_SPACING_RANGE` | `[1.0, 3.0]` | declared, not directly read in flows audited |
| `VOLATILITY_THRESHOLDS` | array | declared; volatility is actually bucketed by `categorizeVolatility()` literals, not this array |

Two private config readers used by the public methods:
- `minOrderIrt(): int` → reads `config('trading.min_order_value_irt')`, falls back
  to `3_000_000` with a `Log::warning`.
- `qtyDecimals(string $symbol): int` → reads
  `config("trading.exchange.precision.{$symbol}.qty_decimals")`, default `8`.

### The eight public methods

| # | Method | Pure? | Touches |
|---|---|---|---|
| 1 | `calculateGridLevels` | ✅ **PURE** | (only `Log::error` on exception) |
| 2 | `calculateOrderSize` | ❌ | exchange + cache + config |
| 3 | `calculateExpectedProfit` | ⚠️ near-pure | attempts exchange (safe fallback) |
| 4 | `assessGridRisk` | ✅ **PURE** | (only `Log::error` on exception) |
| 5 | `healthCheck` | ❌ | exchange + cache (calls #2) |
| 6 | `getOptimalSettings` | ❌ | exchange + cache (calls #1–#4) |
| 7 | `quickMarketAnalysis` | ❌ | exchange + cache |

*(numbering is per-list; table shows 7 rows because the four "core" methods are
1–4 and the three extras are 5–7.)*

---

#### 1. `calculateGridLevels(float $centerPrice, float $spacing, int $levels, string $algorithm = 'logarithmic', $mode = 'both'): array`

- **Signature note:** `$mode` is **intentionally untyped** (no type hint, default
  `'both'`). A comment explains a legacy Livewire caller passes a stray extra
  positional arg here, so non-string values must fall through silently.
- **Computes:** Generates the full set of grid price levels around a center
  price and returns them plus quality/performance metadata. Distributes levels
  by mode: `'both'` → `levels/2` buys below + `levels/2` sells above; `'buy'` →
  all `levels` below; `'sell'` → all `levels` above. Picks a spacing algorithm
  (`logarithmic` default, `arithmetic`, `geometric`), enriches each level, and
  scores the grid.
- **Dependencies (all private, in-class):** `validateGridInputs`,
  `generateGridLevels` → `generateLogarithmicGrid` / `generateArithmeticGrid` /
  `generateGeometricGrid`, `enhanceGridLevels`, `analyzeGridQuality`,
  `calculateGridPerformance`, `calculatePriceRangePercent`. Uses Laravel
  `Collection`, PHP `pow()` and `round()`. **No** DB, exchange, cache, config, or
  `Money`.
- **Purity:** ✅ **PURE.** Deterministic from its arguments; the only side effect
  is `Log::error` inside the `catch`.
- **Edge cases:** `validateGridInputs` throws (Persian message) if
  `centerPrice <= 0`, `spacing` outside `[0.5, 10.0]`, `levels` outside
  `[4, 20]`, or `levels` odd. Invalid `mode` values are coerced to `'both'`
  (with a warning only for non-empty strings). Any exception → returns
  `['success' => false, 'error' => …, 'error_code' => 'GRID_CALCULATION_FAILED']`.
- **Output shape:** `success`, `grid_levels` (Collection of enriched level
  arrays: `price`, `type`, `level`, `distance_percent`, `order_index`,
  `price_formatted`, `execution_probability`, `profit_potential`, `priority`, …),
  `analysis`, `performance`, `algorithm_used`, `total_levels`, `price_range`.
- **⚠️ Prices are `round($price, 0)` raw floats — NOT tick-aligned, NOT `Money`.**

#### 2. `calculateOrderSize(float $totalCapitalIRT, float $activePercent, int $gridLevels, string $symbol = 'BTCIRT'): array`

- **Computes:** Splits active capital across grid levels to get a per-order IRT
  size, converts it to a crypto quantity **at the live current price**, clamps
  to exchange minimums, and returns validation + risk metrics.
- **Dependencies:** `validateCapitalInputs` (reads `minOrderIrt()`→config),
  **`getCurrentPriceWithValidation` → `Cache::remember(...30s...)` +
  `nobitexService->getCurrentPrice($symbol)`**, `calculateCryptoAmount` (reads
  `qtyDecimals()`→config), `optimizeOrderSize` (may re-fetch live price),
  `validateOrderSize`, `calculateOrderRiskMetrics`, `checkExchangeCompatibility`.
- **Purity:** ❌ **NOT pure** — hits the **exchange** and **cache** and config.
  **A calculator UI must not call this as-is** unless the current price is
  injected instead of fetched.
- **Edge cases:** throws if `totalCapital < 10M IRT`, `activePercent` outside
  `(0,100]`, or per-order size `< minOrderIrt()`. `optimizeOrderSize` clamps IRT
  up to `minOrderIrt()` and crypto up to `NOBITEX_MIN_BTC_AMOUNT`, re-deriving
  crypto if IRT was bumped. `usd_equivalent` uses a **hard-coded `/ 42000`**
  (see §7). Exception → `error_code: ORDER_SIZE_CALCULATION_FAILED`.

#### 3. `calculateExpectedProfit(float $centerPrice, float $gridSpacing, int $gridLevels, float $orderSizeCrypto): array`

- **Computes:** Estimates per-cycle gross/net profit and projects it over time.
  `orderNotional = centerPrice * orderSizeCrypto`; `grossProfitPerCycle =
  notional * spacing/100`; subtracts trading fees+slippage; then estimates cycles
  per day, time-frame profits, ROI, success probability, and break-even.
- **Dependencies:** **`analyzeMarketConditions` → (if it exists)
  `nobitexService->getMarketStats('BTCIRT')`** for day-change volatility,
  `calculateTradingFees` (uses `NOBITEX_FEE_RATE` + `EXCHANGE_SLIPPAGE`
  constants), `estimateTradingCycles`, `calculateTimeFrameProfits`,
  `calculateROIMetrics`, `calculateSuccessProbability`,
  `calculateBreakEvenAnalysis`.
- **Purity:** ⚠️ **NEAR-pure but NOT strictly pure.** It *attempts* one exchange
  call (`getMarketStats`) guarded by `method_exists` + `try/catch`; on any
  failure or absence it falls back to `dayChange = 5.0` / `volatility = medium`.
  So it can return without a live exchange, but it will reach out if it can. The
  volatility input therefore makes cycle/profit projections **non-deterministic**
  across calls. For the UI, treat cycle counts, daily/monthly projections,
  success probability, and ROI as **heuristic estimates, not guarantees.**
- **Edge cases:** `profitMargin` guarded against `grossProfit <= 0`.
  `calculateBreakEvenAnalysis` returns `break_even_possible=false` when
  `netProfitPerCycle <= 0`. Exception → `error_code: PROFIT_CALCULATION_FAILED`.
- **⚠️ Fee basis:** fees use the class constant **0.25%**, not the config
  `fee_bps` (0.35%). See §5/§7.

#### 4. `assessGridRisk(array $gridConfig, float $totalCapital): array`

- **Computes:** Scores overall grid risk from three components — price risk,
  liquidity risk, market risk — weighted `0.4 / 0.3 / 0.3`; categorizes the level;
  estimates max/expected loss; and produces recommendations + a stop-loss
  suggestion.
- **Input array keys read:** `center_price`, `spacing`, `levels` (required);
  `active_percent` (defaults to `30` if absent).
- **Dependencies (all private, in-class):** `analyzePriceRisk`,
  `analyzeLiquidityRisk`, `analyzeMarketRisk`, `calculateOverallRiskScore`,
  `categorizeRiskLevel`, `calculateMaxPotentialLoss`,
  `generateRiskRecommendations`, `calculateStopLossRecommendation`. Uses `pow()`,
  arithmetic, `match`. **No** DB, exchange, cache, config, or `Money`.
- **Purity:** ✅ **PURE.** Only side effect is `Log::error` on exception.
- **Edge cases:** Missing required keys (`center_price`/`spacing`/`levels`) would
  raise an undefined-key error that the `catch` converts to
  `['success' => false, 'error_code' => 'RISK_ASSESSMENT_FAILED']`. Loss scenarios
  cap severe decline at `min(50, spacing*6)%`. Max-loss amounts are computed on
  **active** capital (`totalCapital * activePercent/100`).

#### 5. `healthCheck(): array`

- **Computes:** Self-test — runs a sample `calculateGridLevels` and
  `calculateOrderSize`, and (if present) `nobitexService->healthCheck()`.
- **Purity:** ❌ NOT pure (calls #2 → exchange/cache). Diagnostic only; do not
  surface in the calculator UI.

#### 6. `getOptimalSettings(float $availableCapital, string $riskTolerance = 'moderate'): array`

- **Computes:** Picks base settings by risk tolerance
  (`conservative`/`moderate`/`aggressive`), adjusts them to fit capital vs. the
  min-order floor, then runs #1–#4 to produce a recommended config with expected
  results, risk, alternatives, warnings, and next steps.
- **Dependencies:** `getCurrentPriceWithValidation` (**exchange+cache**),
  `adjustSettingsForCapital`, `calculateGridLevels`, `calculateOrderSize`,
  `calculateExpectedProfit`, `assessGridRisk`, `generateAlternativeSettings`,
  `generateSetupWarnings`, `generateNextSteps`.
- **Purity:** ❌ NOT pure (exchange + cache, via price fetch and #2/#3).

#### 7. `quickMarketAnalysis(string $symbol): array`

- **Computes:** Market snapshot + grid-suitability/timing verdict from live price,
  day-change volatility, and spread.
- **Dependencies:** `getCurrentPriceWithValidation` (**exchange+cache**),
  `nobitexService->getMarketStats` (**exchange**), `categorizeVolatility`,
  `analyzeLiquidityLevel`, `assessGridSuitability`,
  `generateMarketBasedRecommendations`.
- **Purity:** ❌ NOT pure (exchange + cache).

---

## 2. The four core methods — confirmed

| Expected name | Exists? | Confirmed signature | Behavior matches name? |
|---|---|---|---|
| `calculateGridLevels` | ✅ Yes | `(float $centerPrice, float $spacing, int $levels, string $algorithm = 'logarithmic', $mode = 'both'): array` | ✅ Yes — generates grid price levels. **Note the extra untyped `$mode` 5th param** not in the "four params" mental model. |
| `calculateOrderSize` | ✅ Yes | `(float $totalCapitalIRT, float $activePercent, int $gridLevels, string $symbol = 'BTCIRT'): array` | ✅ Yes — but **fetches live price internally** (not pure). |
| `calculateExpectedProfit` | ✅ Yes | `(float $centerPrice, float $gridSpacing, int $gridLevels, float $orderSizeCrypto): array` | ✅ Yes — but **volatility/cycle inputs are exchange-derived heuristics**, and fee basis is a hard-coded 0.25%. |
| `assessGridRisk` | ✅ Yes | `(array $gridConfig, float $totalCapital): array` | ✅ Yes — pure risk scoring. **Takes a config array, not scalars** — inputs are `center_price`, `spacing`, `levels`, `active_percent`. |

**Verdict:** all four exist with the believed names. The two safe-for-UI (pure)
ones are `calculateGridLevels` and `assessGridRisk`. `calculateExpectedProfit`
is usable but its projections are heuristic (and reach for the exchange).
`calculateOrderSize` should only be used if the current price is passed in rather
than fetched.

---

## 3. Other honest calculation capability found (secondary scan)

### `app/Support/Math.php` (`final class App\Support\Math`) — pure bcmath helpers
All static, all bcmath-based, all pure. **This is the honest, precise math the
calculator UI *should* prefer over GridCalculatorService's float internals:**
- `percentChange(string $a, string $b, int $scale = 4): string` — `(b-a)/a*100`; throws on zero `$a`.
- `applyPercent(string $a, string $percent, int $scale = 8): string` — `a ± percent%`.
- `gapPercent(string $a, string $b, int $scale = 4): string` — absolute % gap; throws on zero `$a`.
- `safeDiv(string $a, string $b, int $scale = 8): string` — throws `DivisionByZeroError` on zero divisor.
- `gridLevelPrice(string $centerPrice, string $spacingPercent, int $level, int $direction, int $scale = 0): string`
  — **the exact-precision analogue of the grid-level formula**
  `center * (1 ± spacing/100)^level`; `direction` must be `+1` (sell/up) or `-1`
  (buy/down), else throws. Uses `bcpow`.

### `app/Services/GridPlanner.php` (`class App\Services\GridPlanner`) — the REAL placement planner
- `plan(string $symbol, ?int $lastPrice = null, int $levels = 6, float $stepPct = 0.25, string $mode = 'both', int $budgetIrt = 0, ?string $fixedQty = null, ?int $tick = null, ?string $presetBaseQty = null): array`
- **This is what actually builds the orders the bot places.** It computes level
  prices with `Money::mul` / `Money::round` and then **tick-aligns** each price
  via `roundToTick(...)` (buys round down, sells round up), reads `tick`,
  `fee_bps`, `qty_decimals`, `min_order_value_irt` from config, collapses
  duplicate ticks, and validates min-notional. The geometric spacing factor is a
  native-float `pow()` but the price itself is Money-string math + tick rounding.
- **Purity:** ❌ NOT pure — reads `MarketData` (`getLastPrice`) when
  `$lastPrice` is null, and reads config. **But** if `lastPrice` and `tick` are
  supplied, price generation is deterministic. **Key implication for the UI:**
  a truly honest "what will the bot place?" preview should mirror `GridPlanner`
  (Money + tick), because `GridCalculatorService::calculateGridLevels` uses raw
  floats and `round(price,0)` with **no tick alignment** — its prices can differ
  from the ones actually placed.

### Other services under `app/Services/` — scanned, not calculator-relevant
`GridOrderExecutor`, `GridOrderSync`, `SubmissionReconciler`,
`TradingEngineService`, `KillSwitchService`, `MarketDataLayer`,
`NobitexService` / `NobitexAuthService` / `NobitexWebSocketService`,
`WebSocketHealthService`, `BotActivityLogger`, `RateLimiting/*`. These are
execution / sync / exchange / infra concerns — **no pure calculation capability
a calculator UI should surface.** (`TradingEngineService` orchestrates and *uses*
`GridCalculatorService::calculateOrderSize`, per its own comment at line ~642.)

---

## 4. `app/Support/Money.php` helpers available

`final class App\Support\Money`, `declare(strict_types=1)`, pure/stateless, all
static, string-in/string-out. `DEFAULT_SCALE = 20`. **Money math MUST go through
these — NOTE that `GridCalculatorService` currently does NOT use any of them.**

**Arithmetic**
- `add(string $a, string $b, int $scale = 20): string`
- `sub(string $a, string $b, int $scale = 20): string`
- `mul(string $a, string $b, int $scale = 20): string`
- `div(string $a, string $b, int $scale = 20): string` — **throws
  `\DivisionByZeroError` on a zero divisor** (safer than bare `bcdiv`).

**Comparison / min-max**
- `compare(string $a, string $b, int $scale = 20): int` — `-1|0|1`.
- `min(string ...$values): string` — throws `InvalidArgumentException` if no args.
- `max(string ...$values): string` — throws `InvalidArgumentException` if no args.

**Sign predicates**
- `isZero(string $a, int $scale = 20): bool`
- `isPositive(string $a, int $scale = 20): bool`
- `isNegative(string $a, int $scale = 20): bool`

**Utilities**
- `abs(string $a): string` — string-level sign strip.
- `normalize(mixed $v): string` — int/float/string → canonical decimal string;
  renders floats with `%.20F` (no scientific notation); **rejects null/bool/
  NAN/INF/non-scalar** with `InvalidArgumentException`.

**Phase-9 tick / IRT helpers (the tick-alignment the calculator lacks)**
- `round(string $value, int $scale): string` — round half-up; throws on negative scale.
- `alignToTick(string $value, string $tickSize, string $mode = 'floor'): string`
  — snap to a market tick (`floor|ceil|round`); **throws if `tickSize <= 0`**.
- `irtToBase(int $irtAmount, int $priceIRT, int $scale = 8): string` — IRT → crypto; throws if `priceIRT <= 0`.
- `baseToIrt(string $amountBase, int $priceIRT, int $scale = 0): string` — crypto → IRT; throws if `priceIRT <= 0`.
- `trimZeros(string $value): string` — strip insignificant trailing zeros.

> All arithmetic and tick methods rely on `ext-bcmath` at runtime. This audit did
> not execute them.

---

## 5. Relevant config keys + current values (`config/trading.php`)

| Key | Current value / default | Used by |
|---|---|---|
| `trading.min_order_value_irt` | `env('TRADING_MIN_ORDER_VALUE_IRT') ?: 3_000_000` | `GridCalculatorService::minOrderIrt()`, `GridPlanner` |
| `trading.ticks.BTCIRT` / `.ETHIRT` / `.USDTIRT` | `10` / `10` / `10` (`env TICK_*`) | `GridPlanner` (tick). **Not read by GridCalculatorService.** |
| `trading.exchange.fee_bps` | `35` (0.35%) | `GridPlanner`. **⚠️ NOT read by GridCalculatorService — it hard-codes 0.25% via `NOBITEX_FEE_RATE`.** |
| `trading.exchange.fee_rate_percent` | `fee_bps/100 = 0.35` | derived; see fee mismatch in §7 |
| `trading.exchange.slippage_bps` | `10` (0.10%) | config only; GridCalculatorService hard-codes `EXCHANGE_SLIPPAGE = 0.1`. |
| `trading.exchange.allowed_symbols` | `BTCIRT,ETHIRT,USDTIRT` | validation |
| `trading.exchange.precision.BTCIRT.qty_decimals` | `8` (ETH `6`, LTC `6`, USDT `2`) | `GridCalculatorService::qtyDecimals()`, `GridPlanner` |
| `trading.exchange.precision.*.price_decimals` | `0` for all | precision |
| `trading.grid.default_capital` | `100_000_000` | grid defaults |
| `trading.grid.default_active_percent` | `30` | grid defaults |
| `trading.grid.default_spacing_percent` | `1.5` | grid defaults |
| `trading.grid.default_levels` | `10` | grid defaults |
| `trading.grid.max_active_percent` | `80` | grid defaults |
| `trading.grid.default_stop_loss_percent` | `15` | grid defaults |
| `trading.grid.max_drawdown_percent` | `25` | grid defaults |
| `trading.cache.price_ttl` | `30` s | price caching |
| `trading.cache.market_stats_ttl` | `300` s | market-stats caching |

**Hard-coded (in `GridCalculatorService`, NOT config):** fee `0.25%`, slippage
`0.1%`, min BTC `0.000001`, spacing bounds `0.5–10.0`, level bounds `4–20`, min
capital `10M IRT`, and the USD rate `42000`.

**Validation source (`app/Rules/GridValidationRules.php`)** — note these differ
from the service's own guards:
- spacing: `min: MIN_SPACING (0.5)`, `max: MAX_SPACING (10.0)`.
- levels: `min: 4`, `max: 20`, **must be even**.
- capital: `min: 50_000_000` (50M — stricter than the service's 10M constant).
- active percent: `min: 10`, `max: 80` (service allows 1–100).

---

## 6. Relevant `bot_configs` fields (schema; the calculator's inputs should mirror the bot-create form)

Traced across `create_bot_configs_table` + later `ALTER` migrations and confirmed
against `App\Models\BotConfig` (`$fillable` / `$casts`).

**Grid-setup fields the calculator UI mirrors:**

| Column | Type | Default | Meaning |
|---|---|---|---|
| `symbol` | string(32) | `BTCIRT` | trading pair |
| `mode` | string(8) | `buy` | `buy` \| `sell` \| `both` |
| `levels` | integer | `3` | new-gen level count (per-side semantics in planner) |
| `step_pct` | decimal(8,3) | `0.250` | new-gen spacing % between levels |
| `budget_irt` | unsignedBigInteger | `0` | new-gen budget (IRT) |
| `grid_levels` | integer | `10` | **legacy** level count (used by calculator) |
| `grid_spacing` | decimal(5,2) | `1.5` | **legacy** spacing % (used by calculator) |
| `total_capital` | decimal(20,0) | `100000000` | **legacy** total capital IRT (used by calculator) |
| `active_capital_percent` | decimal(5,2) | `30` | **legacy** active % (used by calculator) |
| `center_price` | decimal(20,0) | nullable | **moving** center price (recomputed on re-init) |
| `grid_center_price` | decimal(20,0) | nullable | **stable** Kill-Switch anchor (Phase 11 Step 3) |
| `stop_loss_percent` | decimal(5,2) | `5` → later `15` → back to `5` | stop-loss % (default churned across migrations; current default `5`) |
| `take_profit_percent` | decimal(5,2) | nullable | take-profit % |
| `max_drawdown_percent` | decimal(5,2) | nullable | max drawdown % |
| `rebalance_threshold` | decimal(5,2) | `5.0` | rebalance trigger % |

> **Dual-field caution for the UI:** there are **two parallel sets** of grid
> params — the new-gen `levels` / `step_pct` / `budget_irt` and the legacy
> `grid_levels` / `grid_spacing` / `total_capital` / `active_capital_percent`.
> `GridCalculatorService` is written against the **legacy scalar semantics**
> (center price, spacing %, even level count, active %). `BotConfig::creating`
> backfills new-gen fields from legacy ones. The calculator form's inputs
> (center price, spacing, levels, active %, capital) map cleanly onto the
> **legacy** set — mirror those, and be explicit about which set feeds the bot.

**Exchange/precision mirror fields also on `bot_configs`:** `fee_bps`
(unsignedSmallInt, default `35`), `qty_decimals` (unsignedTinyInt, default `8`),
`tick` (unsignedInt, default `10`), `min_order_value_irt` (nullable).
**These are the per-bot honest fee/tick/precision values** — a truthful
calculator should prefer these (or config) over the service's hard-coded 0.25%.

**Other columns (not calculator inputs, for context):** `name`, `user_id`,
`is_active`, `simulation`, `init_status`, `status` (computed accessor, not a
column), `stop_reason`, `open_cycles_count`, `capital_locked_irt` (decimal(20,0),
uncast for overflow safety), `settings_json`, `last_run_at`, `last_check_at`,
`last_rebalance_at`, `rebalance_count`, `total_profit`, `win_rate`, `started_at`,
`stopped_at`, `last_error_code`, `last_error_message`, `notes`, timestamps.

---

## 7. Do NOT surface these (fake / stub / heuristic / mismatched)

Nothing here is a literal `rand()` stub, but the following are **not honest,
precise numbers** and must not be presented to users as authoritative:

1. **Hard-coded USD conversion `/ 42000`** — `calculateOrderSize` line 201:
   `'usd_equivalent' => round($optimizedSizes['irt_value'] / 42000, 2)`. A frozen
   magic rate; do **not** display USD from this. If USD is needed, use a live
   rate through `Money`.

2. **Fee-rate mismatch (0.25% vs 0.35%)** — the service hard-codes
   `NOBITEX_FEE_RATE = 0.25` and `EXCHANGE_SLIPPAGE = 0.1`, while config/DB say
   `fee_bps = 35` (0.35%) and `slippage_bps = 10`. Every profit/fee/break-even
   number from `calculateExpectedProfit` and `checkExchangeCompatibility` is
   therefore computed on the **wrong (too-low) fee**. Do not surface these fee/
   profit figures as accurate; if surfaced, drive fees from `fee_bps`.

3. **Prices are raw-float, non-tick-aligned** — `calculateGridLevels` (and all
   three `generate*Grid` methods) use `pow()`/`round($price, 0)` in native
   floats and **never** call `Money` or `alignToTick`. The real planner
   (`GridPlanner`) uses `Money` + `roundToTick`. **The calculator's level prices
   can differ from the orders the bot actually places.** Label calculator prices
   as *indicative*, or recompute via `Math::gridLevelPrice` / `GridPlanner`
   semantics for a faithful preview.

4. **Heuristic "market" numbers dressed as analysis** — cycle counts
   (`estimateTradingCycles`), `calculateSuccessProbability` (base 75% ± fixed
   deltas), `execution_probability` (fixed `match` buckets), `grid_efficiency`,
   ROI projections, and the `daily/weekly/monthly/yearly` extrapolations are all
   **fixed-lookup heuristics**, several multiplied by an exchange-derived
   `dayChange` that falls back to a constant `5.0` when the exchange is
   unavailable. They are estimates, not computed guarantees — surface with clear
   "estimate" framing or not at all.

5. **`VOLATILITY_THRESHOLDS` constant is effectively dead** — declared but the
   actual volatility bucketing is done by literal thresholds inside
   `categorizeVolatility()`, so the constant is not the source of truth. Harmless,
   but don't wire a UI to it expecting it to drive behavior.

6. **`healthCheck()` / diagnostic surface** — self-test method (also mirrored by
   a debug path in `routes/web.php` ~242–310). Not a user calculator feature.

---

## 8. Open questions / ambiguities not resolvable by reading

1. **Which param set is canonical for the new UI** — new-gen (`levels`,
   `step_pct`, `budget_irt`) vs legacy (`grid_levels`, `grid_spacing`,
   `total_capital`, `active_capital_percent`)? The calculator service speaks
   legacy; the placement planner speaks new-gen (`stepPct`, per-side `levels`).
   The UI needs a decision on which it presents and how it maps between them.

2. **Fee source of truth** — should the calculator use per-bot `bot_configs.fee_bps`,
   global `config('trading.exchange.fee_bps')`, or (as today) the wrong hard-coded
   0.25%? (Recommend config/DB; flagged in §7.2 — not fixing here.)

3. **Should the calculator preview real placed prices?** If yes, it likely needs
   `GridPlanner`/`Money` tick-aligned math rather than
   `GridCalculatorService::calculateGridLevels`. Confirm the intended fidelity
   ("indicative" vs "exactly what will be placed").

4. **Current-price injection** — `calculateOrderSize` and `getOptimalSettings`
   fetch the live price internally (exchange+cache). For a pure UI, is a price
   passed in from the form/live ticker, or is a fetch acceptable on calculate?

5. **`calculateExpectedProfit` volatility** — acceptable that projections vary
   with live `getMarketStats` (and silently fall back to `dayChange=5.0`)? This
   makes the same inputs yield different outputs at different times.

6. **`stop_loss_percent` default churn** — migrations set `5` → `15` → `5`. The
   final state read is default `5`, but the `config('trading.grid.default_stop_loss_percent')`
   is `15`. Confirm which default the create form should present.

7. **`ext-bcmath` availability in the target runtime** — `Money`/`Math` require
   it; not verified here (reading task). Confirm it is present wherever the honest
   money-math calculator will run.
