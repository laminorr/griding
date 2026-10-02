# Fee Audit: BTCIRT Grid Bot (read-only investigation)

*Base commit:* `origin/main` @ `cd708f6`. *Scope:* every place where exchange fees affect order sizing, balances, recorded profit, and what the panel shows.
*Method:* static code reading plus exact `App\Support\Money` (bcmath) simulations (script and output in Appendix A). No application code, config, test or migration was changed. Nobitex was not called.

**How labels are used in this report.** **VERIFIED** means it comes from the live-account trade rows the operator supplied (buy fee is charged in BTC at exactly 0.25% of amount). **DOCS** means Nobitex documentation only. **UNVERIFIED** means an assumption; each one is tied to a host check in §F. Every conclusion names the assumption it depends on.

---

## 1. Executive summary

1. **CRITICAL: buy-first exit SELL is sized larger than the BTC the buy delivered.** `CheckTradesJob.php:933` sizes the pair sell at the *gross* filled amount `q`. The account only received `q·(1−0.0025)` (VERIFIED). If there is no free BTC, the exit sell is rejected for insufficient balance. That rejection is misclassified as `submission_unknown` (`CheckTradesJob.php:1116`). The reconciler then cancels and unlinks the row, the job re-pairs with the same amount, and the cycle repeats indefinitely. The bought BTC is never sold, so the cycle never closes. If free BTC does exist, each cycle silently sells 0.25% of `q` from the operator's *other* BTC (0.0000144676 BTC ≈ 3.1M IRT per 1.25B-IRT level).
2. **HIGH: the bot never reads a real fee.** `OrderStatusDto::fromApi` drops `fee` and `averagePrice`. `grid_orders` has no fee column. `CompletedTrade` books `fee = 35 bps × (buyNotional + sellNotional)` from config. The observed buy rate is 25 bps. Recorded net profit is about **20% too low** per cycle if the sell fee is 0.25% in rial (DOCS/UNVERIFIED), and about 10% too low if it is 0.35%.
3. **HIGH: wrong numbers can stop a profitable bot.** The engine and panel break-even is 0.7025% spacing (35 bps both legs). The true break-even is about 0.50% (25/25). For any spacing in [0.50%, 0.70%), which `MIN_SPACING = 0.5` allows, every trade is recorded as a loss even though it truly made money. `KillSwitchService` sums only losing `net_profit`, so it can trip `max_drawdown` on a profitable bot.
4. **HIGH: simulation hides problem #1.** Simulated fills never deduct fees from quantity and never check balances. Simulated exit sells therefore always succeed, so simulation P&L and inventory cannot match live.
5. **MEDIUM: BTC inventory erodes in every cycle and nothing tracks it.** Sell-first cycles return `q·(1−0.0025)` BTC, losing 0.0000144676 BTC per 1.25B-IRT cycle, or 0.00144676 BTC (≈312.5M IRT) over 100 cycles. Rebalance sells are sized from the IRT budget with no BTC check, so the deficit eventually surfaces as the same rejection loop described in #1.

---

## A. Inventory of fee touchpoints

### A.1 Configuration and model

| # | Location | Code (quoted) | Notes |
|---|---|---|---|
| A1 | `config/trading.php:35-36` | `'fee_bps' => (int) env('TRADING_EXCHANGE_FEE_BPS', 35), // 0.35%`<br>`'fee_rate_percent' => ((int) env('TRADING_EXCHANGE_FEE_BPS', 35)) / 100.0,` | A single rate for both legs, both currencies, maker and taker. `fee_rate_percent` has no reader (grep). |
| A2 | `config/trading.php:47` | `'BTCIRT'  => ['price_decimals' => 0, 'qty_decimals' => 8],` | `qty_decimals` = 8 for BTCIRT. |
| A3 | `config/trading.php:14` | `'min_order_value_irt' => ... ?: 3_000_000` | Relevant to dust (dust can never be sold alone). |
| A4 | migration `2025_08_21_000001_…:50` | `$table->unsignedSmallInteger('fee_bps')->default(35)` | `bot_configs.fee_bps` is NOT NULL, default 35. |
| A5 | `app/Models/BotConfig.php:35,69` | `'fee_bps', // کارمزد به bps (مثلاً 35 = 0.35%)` / `'fee_bps' => 'integer'` | Fillable. **No Filament form sets it** (grep over `app/Filament` finds no `fee_bps` form field), so every bot carries 35. `database/factories/BotConfigFactory.php:61` also uses 35. |
| A6 | `bot_configs.qty_decimals` (migration `…:54`, `BotConfig.php:36,70`) | default 8 | **Never read** by any sizing code. Everything reads `config("trading.exchange.precision.$symbol.qty_decimals")`. |

**Readers of the fee rate:**

| Reader | Source used | Line |
|---|---|---|
| `GridPlanner::plan` | config only (ignores the bot's column) | `GridPlanner.php:58` `$feeBps = (int) (config('trading.exchange.fee_bps') ?? 35);` |
| `CompletedTrade::createFromOrders` | bot, then config | `CompletedTrade.php:394` |
| `CheckTradesJob::recordCompletedTrade` (log only) | bot, then config | `CheckTradesJob.php:1152` |
| `TradingEngineService::verifySufficientQuoteBalance` | bot, then **wrong config key** `trading.fee_bps` (does not exist; falls back to the literal 35) | `TradingEngineService.php:441` |
| Panel `GridCalculator` | the planner's `fee_bps` (that is, config) | `GridCalculator.php:191` |
| `GridCalculatorService` | **hard-coded** `NOBITEX_FEE_RATE = 0.25` plus 0.1% slippage | `GridCalculatorService.php:32-33` |
| `TestNobitexApi` command (display only) | config | `TestNobitexApi.php:324` |

There are three different fee models in the codebase: 35 bps on both legs (engine and panel), 0.25%+0.25%+0.1% slippage on the *buy* notional (`GridCalculatorService`), and a 35 bps IRT buffer on the buy-side preflight.

### A.2 Planning and sizing

**GridPlanner** (`app/Services/GridPlanner.php`)
- `:58-59`: fee rate and `qty_decimals` both come from config.
- `:179-180` (budget sizing): `$qtyRaw = Money::div(... $qtyDecimals + 10); $qty = $this->formatQty((float) $qtyRaw, $qtyDecimals);`
- `:240-245` `formatQty`: `number_format($qty, $dec, '.', '')`. This **rounds half-up**; it does not truncate. A planned quantity can be up to 0.5e-8 above the exact value.
- `:146-160` (balance-aware sell sizing, `presetBaseQty`): it divides the held BTC across the sell levels and passes each share through the same rounding `formatQty`. The sum of the per-level sells can exceed the held BTC by up to `n × 0.5e-8`. `TradingEngineService:752-765` then drops whichever last sell no longer fits. This is not fee-related, but it is the same truncation family.
- `:197-198`: `estimated_fee_irt = ceil(Σnotional × fee_bps / 10000)`. This figure is for reporting and logs only and does not affect any order.
- **No fee awareness in sizing at all.** Sells are not reduced for the BTC fee on the buy that funds them.

**GridCalculatorService** (`app/Services/GridCalculatorService.php`)
- `:32` `const NOBITEX_FEE_RATE = 0.25;`, `:33` `EXCHANGE_SLIPPAGE = 0.1`.
- `:589-596` `calculateCryptoAmount`: `round($cryptoAmount, $precision)`, again half-up. This produces `orderSize`, which `TradingEngineService:128,708` turns into `fixedQty` through `sprintf('%.8f')`.
- `:256-265` `calculateExpectedProfit`: `gross = notional × spacing`, `total_cost = 2 × notional × 0.25% + notional × 0.1%`. It is used by `CreateBotConfig.php:266` (the create wizard), not by the engine. The sell leg's fee is charged on the buy notional, and the BTC-denominated buy fee is not modeled.
- `:677` `estimated_fee` uses 0.25% as well.
- **No "minimum profitable spacing" function exists.** `MIN_SPACING = 0.5` (`:38`) is a static validation bound in `GridValidationRules.php:14`.

**Panel GridCalculator** (`app/Filament/Pages/GridCalculator.php:191-263`)
- `:223` `$this->feePerCycle = (int) round($repNotional * $f * (2 + $s));` with `f = 35/10000`. It uses the buy-first formula for *every* level, including sell-initiated cycles (whose true multiplier is `2 − s`), and the config rate rather than any bot's rate. Break-even as shown: `s = 2f/(1−f)` = **0.7025%**.

### A.3 Order placement

**`CreateOrderDto::toApiPayload`** (`app/DTOs/CreateOrderDto.php:49-58`): this path truncates DOWN.
```php
$amountPrecision = (int) (config("trading.exchange.precision.{$symbol}.qty_decimals") ?? 8);
...
$decimal = substr($decimal, 0, $amountPrecision);
```
It is only used by `NobitexService::createOrder`, which is called from `GridOrderExecutor:263` (initial grid and rebalance).

**`NobitexService::placeOrder`** (`app/Services/NobitexService.php:1222-1245`) is used for every **pair/exit order** (`CheckTradesJob.php:1068`). It sends `'amount' => (string)$quantity` **verbatim, with no truncation**. Today that is harmless, because the pair amount comes from a `decimal(20,8)` column. It becomes a live hazard the moment someone computes a fee-adjusted amount with 10–12 decimals (see C5).

**`GridOrderExecutor::applyForBot`** (`app/Services/GridOrderExecutor.php:145-300`): it stores `amount = $quantity` from the plan and sends it via `CreateOrderDto`. It performs **no balance check**, for IRT or BTC. On any exception after the API call it sets `'status' => $apiCallAttempted ? 'submission_unknown' : 'cancelled'` (`:301`).

**`TradingEngineService::initializeGrid` and `placeGridOrders`** (`app/Services/TradingEngineService.php`)
- `:400-465` buy-side preflight: `required = Σ(buyPrice × orderSize) × (1 + fee_bps/10000)`. It adds an IRT fee buffer, but the buy fee is charged in **BTC** (VERIFIED), so the IRT buffer is unnecessary. It is conservative and harmless.
- `:441`: wrong key `config('trading.fee_bps', 35)`. It is unreachable in practice because the column is NOT NULL.
- `:678-681` sell-side balance: `$btcBalance = Money::normalize($balances[$baseCurrency]['available'] ?? 0);`
- `:723` `computePresetBaseQty` passes the **entire** available BTC to the sell levels (`:937-950`). After initialization, free BTC is therefore about 0. That matters for C1.
- `:745-770`: drops sells the account cannot cover. This is the only BTC balance check anywhere, and it runs at initialization only.
- **Fees are never considered on the sell side.**

**`NobitexService::getBalances`** (`:1198-1215`):
```php
'available' => (string) ($w['balance'] ?? '0'),
'locked'    => (string) ($w['blocked'] ?? '0'),
```
**UNVERIFIED:** if Nobitex's wallet object uses `blockedBalance`/`activeBalance` (as we recall from the docs), then `available` here is the *total* balance including BTC locked in open sells, and `locked` is always `'0'`. The initialization BTC pre-filter would then over-count free BTC. Check F3.

### A.4 Fill processing: `CheckTradesJob`

**`OrderStatusDto::fromApi`** (`app/DTOs/OrderStatusDto.php:42-56`) reads only `id`, `status`, `type`, `execution`, `amount`, `matchedAmount|filled`, `price` (cast to int), `createdAt` and `updatedAt`.
```php
$filled    = (string) ($row['matchedAmount'] ?? $row['filled'] ?? '0');
$price     = isset($row['price']) ? (int) $row['price'] : null;
```
**Dropped:** `fee`, `averagePrice`, `totalPrice`, `unmatchedAmount`, `partial`, `isMaker`. There are no other order parsers: `getOrdersStatus` (`NobitexService.php:645-690`) always uses `fromApi`. `listRecentTrades` returns raw rows, and its only consumer (`SubmissionReconciler`) matches on id, price, side and amount, **never `fee`**. `ExchangeWsEventRecorder` stores the whole payload as JSON (fee included) but reads only `filledAmount` and status. `ProcessOrderEventJob` re-polls REST and does not use the payload (`CheckTradesJob.php:390`: "The exchange REST status is the only input; no event payload is used").

**Conclusion: no code path reads any exchange fee field today.**

**`handleFilledOrder`** (`:537-597`):
```php
$filledBase = ((float) $statusDto->filledBase > 0) ? $statusDto->filledBase : (string) $order->amount;   // :555-557
'filled_amount'      => $filledBase,                                    // :576  GROSS matched amount
'average_fill_price' => $statusDto->priceIRT ?? $order->price,          // :580  the LIMIT price, not average
```
**`handlePartialFill`** (`:621-662`) and **`handleCanceledOrder`** (`:692-748`) behave the same way: gross `filledBase`, and the limit price stored as `average_fill_price`.

**`createPairOrderLocked`** (`:904-1135`): the pair amount.
```php
$pairAmount = $filledOrder->filled_amount ?? $filledOrder->amount;      // :933
...
'amount' => $pairAmount,                                                 // :995 intent row
$apiResponse = $nobitexService->placeOrder($symbol, $newType, $newPrice, (string) $pairAmount, $clientOrderId); // :1068
```
For a filled **buy**, the exit **sell** amount is the gross matched BTC. For a filled **sell**, the exit **buy** amount is the same BTC quantity, which then credits only `q·(1−f_b)`.

On failure after the API call (`:1108-1116`):
```php
if ($apiCallAttempted) { $newOrder->update(['status' => 'submission_unknown']); }
```
`throwDomainError` maps `InsufficientBalance` to a plain `\RuntimeException('Insufficient balance')` (`NobitexService.php:466`), and it is raised *after* `$apiCallAttempted = true`. A **definitive rejection is therefore parked as ambiguous.** `SubmissionReconciler::resolveAsCancelled` (`SubmissionReconciler.php:411-455`) later cancels the row and **unlinks the parent fill** (`paired_order_id = null`). `processBot` (`:124-133`) then selects that fill again and re-places the *same* amount. The loop period is roughly `min_age_seconds` (300 s) plus `not_found_confirmations` (2) × the 5-minute reconcile cadence.

**`processSingleOrder`** (W4, `:398-460`) runs the same `processOrderStatus` and `createPairOrder`, so it has the same behavior. Fee data in the triggering WS event is ignored.

**`checkSimulatedOrders`** (`:196-247`): `$order->update(['status' => 'filled', 'filled_at' => now()]);`. No `filled_amount` is set, no fee is applied, and no balance is touched.

### A.5 Profit booking

**`CompletedTrade::createFromOrders`** (`app/Models/CompletedTrade.php:332-445`):
```php
$buyPrice   = Money::normalize($buyOrder->price);          // :359 LIMIT price
$buyAmount  = Money::normalize($buyOrder->amount);         // :361 REQUESTED amount (not filled_amount)
$sellAmount = Money::normalize($sellOrder->amount);        // :362
$amount = Money::min($buyAmount, $sellAmount);             // :365
$grossProfit = Money::mul(Money::sub($sellPrice, $buyPrice), $amount);      // :380
$feeBps  = $buyOrder->botConfig?->fee_bps ?? config('trading.exchange.fee_bps', 35);   // :394
$totalFee     = Money::mul($feeRate, Money::add($buyNotional, $sellNotional));          // :399
$netProfit = Money::sub($grossProfit, $totalFee);                                       // :402
'profit' => $netProfit, 'fee' => $totalFee, 'gross_profit' => ..., 'net_profit' => ...  // :420-423
```
- Units are all rial. The BTC buy fee is implicitly valued at the buy price through `fee_rate × buyNotional`.
- Columns: `profit` and `fee` are `DECIMAL(20,0)` (migration `2025_07_24_215225`, `:22-23`), so they are rounded to whole rial. `gross_profit` and `net_profit` are `DECIMAL(20,8)`.
- No fee currency, no source (actual or estimated), and no maker flag are stored.
- **Important structural observation, proven numerically in C1 and C2:** with *correct per-leg rates*, `f_b·buyNotional + f_s·sellNotional` is the economically exact fee when the BTC fee is valued at the buy price. The formula's *shape* is right. The *inputs* are wrong: one configured rate of 35 bps instead of real per-leg fees, limit prices instead of average fill prices, and requested amounts instead of filled amounts.

**`CheckTradesJob::recordCompletedTrade`** (`:1140-1218`) recomputes the same numbers in **float** (`$feeBps / 10000.0`, `:1153-1156`) for the activity log and for `logTradeCompleted` (`'profit' => $netProfit, 'fee' => $totalFee`). It uses `$buyOrder->amount` (`:1146`), not the min of the two legs. The logged numbers can therefore differ from the stored row when the leg amounts differ. `:846` logs `gross = (sell − buy) × buyOrder->amount` with native `*`.

### A.6 Profit consumers

| Consumer | Line | What it reads | Effect of wrong fee |
|---|---|---|---|
| `KillSwitchService::evaluateMaxDrawdown` | `KillSwitchService.php:147-154` | `SUM(net_profit) WHERE net_profit < 0` (losses only, wins ignored) | False losses when spacing is in [~0.50%, 0.7025%); see C4 and D3. |
| `GridOrderObserver::recomputeInventoryForBot` | `GridOrderObserver.php:128` | `capital_locked = buy.price × buy.amount` | Uses the gross amount at the limit price. Ignores that only `q(1−f_b)` BTC is held. Small (0.25%) overstatement of locked value; fee-agnostic otherwise. |
| `AdjustGridJob` | `AdjustGridJob.php:159-225` | `effectiveBudget = total_capital − capital_locked_irt`; `plan(... budgetIrt)` with **no `presetBaseQty` and no BTC check** | Sells sized from IRT. In live mode those sells need BTC that fee drift has eroded. There is no `total_profit` or rebalance P&L booking in this job (grep). |
| `BotConfig::getTotalProfitAttribute` and others | `BotConfig.php:266-305` | `SUM(profit)`, `profit > 0` | Inherit the 35 bps error. |
| `BotConfigResource` "profitable" filter | `BotConfigResource.php:433-435` | `SUM(profit - COALESCE(fee, 0)) > 0` | **Double-counts the fee**, because `profit` is already net. This is an inconsistency left behind by the cleanup that fixed `BotConfig.php:269-284`. |
| `EditBotConfig` | `EditBotConfig.php:265,278` | `SUM(profit)`, `profit > 0` | Inherits the error. |
| `ListBotConfigs` | `:186,222,292,298` | `SUM(profit)` | Inherits the error. |
| `BotMonitoring` page | `BotMonitoring.php:158,204,208,267-268,715-719` | daily, 24h, total `sum('profit')`, per-trade profit | Inherits the error (cycle analytics are durations, fee-free). |
| `BotStatusWidget`, `PerformanceChartWidget` | `BotStatusWidget.php:35,39,46,53`; `PerformanceChartWidget.php:29` | `sum('profit')`, `profit > 0` | Inherits the error. |
| `CompletedTrade` accessors and stats | `CompletedTrade.php:146-236,463-525` | `profit`, ROI = `profit / (buy_price × amount)` | Inherits the error. |
| `BotActivityLogger::logTradeCompleted` | via `CheckTradesJob.php:1192-1200` | float `netProfit`/`totalFee` | Inherits the error. |

### A.7 Simulation mode

- Initial and rebalance orders are created as `placed` with `SIM-*` ids (`GridOrderExecutor.php:198-216`). There are no balances.
- A fill happens when the market crosses the price (`CheckTradesJob.php:213-236`). `filled_amount` stays NULL, so the pair uses `amount` (gross) at `:933`. No fee is deducted from quantities, and the exit always "succeeds".
- `TradingEngineService` skips all balance checks for simulation bots (`:402-404`, `:677`), and `presetBaseQty` is always null (`:870-872`).
- `CompletedTrade` still books 35 bps on both legs. Simulated P&L therefore equals the engine's *model*, and simulated inventory never drifts or fails.

### A.8 Data already available but unused

- `exchange_ws_events.payload` (JSON) holds `private:orders` (`fee`, `filledAmount`, `avgFilledPrice`) and `private:trades` (`fee`, `price`, `amount`, `total`, `isMaker`). There is no dedicated column; everything is in `payload` (migration `2026_10_01_000001`, `:34-51`).
- `GET /market/trades/list` (`listRecentTrades`) returns per-trade `fee`. It covers recent trades only (window/limit UNVERIFIED).
- `POST /market/orders/status` returns an `order` object. Per DOCS it includes `fee` and `averagePrice`; the exact keys are UNVERIFIED (check F2).

---

## B. Correctness against the verified facts

The fact IDs come from the brief: V1 is "buy fee in BTC, 0.25% of amount" (VERIFIED). D2 is "sell fee in rial" (DOCS, UNVERIFIED). D3 is "the rate is tier/maker-taker dependent" (UNVERIFIED).

| Touchpoint | Verdict | Why | Depends on |
|---|---|---|---|
| `config fee_bps = 35` and `bot_configs.fee_bps` default 35 | **Wrong** | The observed rate is 25 bps. One rate for both legs cannot express a BTC-side buy fee plus a rial-side sell fee, or maker vs taker. | V1, D3 |
| `GridPlanner` sizing (buy qty) | Correct | The buy amount is what we want to spend; the fee comes out of the BTC received, not the IRT. | V1 |
| `GridPlanner` `presetBaseQty` sells | Mostly correct | Sized from held BTC. Rounding half-up can over-allocate by up to 1e-8 per level; the engine's pre-filter then drops the last sell. | none |
| `GridPlanner` `estimated_fee_irt` | Wrong (cosmetic) | 35 bps on Σnotional, log only. | V1 |
| `GridCalculatorService` fee math | Wrong (inconsistent) | Hard-coded 0.25% (accidentally the observed buy rate), sell fee on the buy notional, plus 0.1% "slippage" on limit orders. Not used by the engine. | V1, D2 |
| Panel calculator | Wrong | 35 bps, buy-first formula for all levels, break-even shown as 0.7025%. | V1, D2 |
| `CreateOrderDto` truncation | Correct | Truncates DOWN to 8 dp. | none |
| `placeOrder` (pair path) amount | Correct today, **fragile** | No truncation. Safe only while amounts come from an 8-dp column. | none |
| Initial buy-side IRT preflight (+35 bps buffer) | Over-conservative | The buy fee is in BTC, so IRT needed equals the notional. Harmless. | V1 |
| Initial sell-side BTC pre-filter | Correct in intent; **unknown** in effect | Uses `getBalances()['btc']['available']`, which may be total rather than free. | F3 |
| `OrderStatusDto::fromApi` | **Incomplete** | Drops `fee` and `averagePrice`. | V1 / F2 |
| `handleFilledOrder` `filled_amount` | Correct as "gross matched"; ambiguous as a ledger | It is the gross matched amount. Net received (BTC for buys) is nowhere. | V1 |
| `average_fill_price = priceIRT` | **Wrong when price improves** | `price` is the order's limit price. A crossing limit order fills at better prices that are never captured. | F2 |
| `createPairOrderLocked`, filled buy → exit sell | **Wrong** | Sells `q` but holds only `q(1−0.0025)`. Fails, or consumes foreign BTC. | V1 only |
| `createPairOrderLocked`, filled sell → exit buy | Correct as an order; **inventory leaks** | The order is valid, but BTC returns as `q(1−f_b)`. Nothing records or compensates for the shortfall. | V1 |
| InsufficientBalance → `submission_unknown` | **Wrong** | A definitive domain rejection is treated as ambiguous. That produces a retry loop through the reconciler. | code only (assumes the exchange returns `InsufficientBalance`, F7) |
| `CompletedTrade` profit formula shape | Correct shape | `f_b·buyNotional + f_s·sellNotional` equals the true net when the BTC fee is valued at the buy price. | V1, D2 |
| `CompletedTrade` inputs | **Wrong** | 35 bps instead of the actual per-leg fee, limit price instead of average, `amount` instead of `filled_amount`. No currency, source or maker flag. | V1, D2, D3 |
| `recordCompletedTrade` log numbers | Wrong (cosmetic) | Float math, `buyOrder->amount` instead of the min of the legs. | none |
| KillSwitch drawdown | **Wrong in a band** | Uses wrong `net_profit`. In [~0.50%, 0.7025%) spacing every trade looks like a loss. Also counts only losses (design choice, noted). | V1, D2 |
| Observer `capital_locked_irt` | Slightly wrong | Uses the gross amount at the limit price (+0.25%). | V1 |
| `BotConfigResource` profitable filter | **Wrong** | Subtracts the fee twice. | none |
| Other KPIs (Dashboard, Monitoring, List, Edit) | Wrong by inheritance | All read `profit`. | V1, D2 |
| Simulation | **Wrong model** | No fee on quantities, no balances, so it never reproduces live failures. | V1 |

---

## C. Worked numeric simulations

**Parameters.** Price `P = 216,000,000,000` IRT/BTC (rial). Spacing `s = 1.5%`. `qty_decimals = 8` (config). Buy fee `f_b = 0.25%` in BTC (VERIFIED). Sell fee `f_s = 0.25%` in rial (**DOCS-based, UNVERIFIED**), with a sensitivity case at `f_s = 0.35%`. The configured rate the bot books is 35 bps. All arithmetic uses `App\Support\Money`. "True net" values the BTC delta at the buy price unless stated otherwise.

Order quantities follow `GridPlanner::formatQty` (round half-up to 8 dp):

| Case | Target notional | `q` (BTC) | Actual buy notional at P |
|---|---|---|---|
| Large (5B capital / 4 levels) | 1,250,000,000 | **0.00578704** | 1,250,000,640 |
| Small | 5,000,000 | **0.00002315** | 5,000,400 |

Exit prices (`createPairOrderLocked:910-919`): buy-first exit sell = `P·1.015 = 219,240,000,000`. Sell-first exit buy = `P·0.985 = 212,760,000,000`.

### C1. Buy-first cycle (buy fills → exit sell → sell fills)

**What happens to BTC:**

| | Large | Small |
|---|---|---|
| Buy amount sent and filled (`q`) | 0.00578704 | 0.00002315 |
| Buy fee charged in BTC (`q × 0.0025`) | 0.0000144676 | 0.000000057875 |
| BTC credited (`q × 0.9975`) | 0.0057725724 | 0.000023092125 |
| **Exit SELL amount the bot sends today** (`filled_amount`) | **0.00578704** | **0.00002315** |
| BTC shortfall versus this cycle's own BTC | 0.0000144676 (≈3.13M IRT) | 0.000000057875 (≈12.5k IRT) |
| Fee-aware sell `floor8(credited)` | 0.00577257 | 0.00002309 |
| Dust left by the fee-aware sell | 0.0000000024 (≈526 IRT) | 0.000000002125 (≈466 IRT) |

**Can the exit sell fail?** This relies only on V1 plus the standard exchange rule that a sell needs free base balance.
- **No free BTC:** it **fails on the first cycle**, every time. This is the normal state after an initialization with `presetBaseQty`, because initialization dedicates *all* available BTC to sells (`TradingEngineService.php:937-950`). It is also the normal state for a pure-IRT account. The failure then loops (§A.4): the exit row goes `submission_unknown`, then the reconciler sets it to `cancelled` and unlinks it, the job re-pairs, and the order is rejected again, about every 10–15 minutes. Meanwhile the bought BTC is stranded, no `CompletedTrade` is booked, and the level stays idle.
- **With free BTC:** the sell succeeds by consuming `q × 0.0025` of the operator's other BTC per cycle. In the large case, free BTC of 0.0001 covers 6 cycles, 0.001 covers 69 cycles, and 0.01 covers more than 100.

**Profit (large case):**

| Quantity (IRT) | `f_s = 0.25%` (DOCS) | `f_s = 0.35%` |
|---|---|---|
| Recorded today by `CompletedTrade` (35 bps both legs): gross / fee / net | 18,750,009.60 / 8,815,629.51 / **9,934,380.09** | same: **9,934,380.09** |
| Today with spare BTC: rial Δ | +15,578,132.98 | +14,309,382.33 |
| Today with spare BTC: BTC Δ | −0.0000144676 | −0.0000144676 |
| **True net** (BTC at buy price) | **12,453,131.38** | **11,184,380.73** |
| True net (BTC at sell price) | 12,406,256.35 | 11,137,505.70 |
| Recorded − true | **−2,518,751.29 (−20.2%)** | **−1,250,000.64 (−11.2%)** |
| Correct-rate formula `f_b·buyN + f_s·sellN` | 12,453,131.38 (exact match) | 11,184,380.73 (exact match) |
| Fee-aware sell (`floor8` net), true net incl. dust | 12,414,179.58 | 11,148,601.34 |
| Fee-aware: recorded − true | −2,479,799.50 | −1,214,221.25 |

**Small case:** recorded 39,740.68. True net 49,816.49 at `f_s` 0.25% (error −10,075.81) and 44,741.08 at 0.35% (error −5,000.40). The fee-aware true net is 49,654.97 and 44,592.72.

**Interpretation.** The bot *under*-reports profit, which is the conservative direction. But it also does not show that part of the cost was paid in BTC: the rial balance rises by 15.58M while BTC drops by 0.0000144676.

### C2. Sell-first cycle (sell fills → exit buy → buy fills)

The sell is backed by inventory (preset or held BTC). The bot sells `q` at `P` and buys back `q` at `0.985·P`.

| Quantity | Large | Small |
|---|---|---|
| BTC back after the exit buy | 0.0057725724 | 0.000023092125 |
| **Inventory change per cycle** | **−0.0000144676 BTC** | **−0.000000057875 BTC** |
| Recorded today (net) | **10,065,630.15** | **40,265.72** |
| `f_s` 0.25%: rial Δ / true net / recorded − true | 15,625,008 / **12,546,881.42** / −2,481,251.27 | 62,505 / 50,191.52 / −9,925.79 |
| `f_s` 0.35%: rial Δ / true net / recorded − true | 14,375,007.36 / **11,296,880.78** / −1,231,250.63 | 57,504.60 / 45,191.12 / −4,925.39 |
| Inventory-restoring buy `ceil8(q/(1−f_b))` | 0.00580155 → rial Δ 12,537,860.40 (0.25%), BTC +0.000000006125 | 0.00002321 → rial Δ 49,739.40, BTC +0.000000001975 |

The inventory shrinks by exactly the buy fee in **every** sell-first cycle. Nothing records this or compensates for it.

### C3. 100 consecutive cycles

Each cycle in the bot starts from a fresh grid order: one initial or rebalance order produces one exit, then the level waits for `AdjustGridJob` (`processBot` only pairs fills with `paired_order_id IS NULL`).

| 100 cycles | Large: `f_s` 0.25% | Large: `f_s` 0.35% | Small: `f_s` 0.25% | Small: `f_s` 0.35% |
|---|---|---|---|---|
| Cumulative BTC drift | −0.00144676 BTC (≈312.5M IRT) | same | −0.0000057875 BTC (≈1.25M IRT) | same |
| Buy-first: recorded Σ | 993,438,009 | 993,438,009 | 3,974,068 | 3,974,068 |
| Buy-first: true Σ | 1,245,313,138 | 1,118,438,073 | 4,981,649 | 4,474,108 |
| Buy-first: error | −251,875,129 | −125,000,064 | −1,007,581 | −500,040 |
| Buy-first: rial-only Σ (what the IRT wallet shows) | 1,557,813,298 | 1,430,938,233 | 6,231,749 | 5,724,208 |
| Sell-first: recorded Σ | 1,006,563,015 | 1,006,563,015 | 4,026,572 | 4,026,572 |
| Sell-first: true Σ | 1,254,688,142 | 1,129,688,078 | 5,019,152 | 4,519,112 |
| Sell-first: error | −248,125,127 | −123,125,063 | −992,579 | −492,539 |

**When do orders start failing?**
- **Buy-first with no free BTC:** at cycle 1, then indefinitely (the rejection loop).
- **Buy-first with free BTC `S`:** after `floor(S / (q·0.0025))` cycles. In the large case that is 6 cycles for 0.0001 BTC, 69 for 0.001, and more than 100 for 0.01. The 100-cycle buy-first rows above therefore assume at least 0.00144676 BTC of free spare.
- **Sell-first:** the exit buys never fail for BTC reasons. The *next* sells for those levels come from `AdjustGridJob` sized from the IRT budget (`AdjustGridJob.php:220-226`, no preset and no BTC check). After about `held_BTC / (q·0.0025)` sell-first cycles, those rebalance sells start getting `InsufficientBalance`, and they also become `submission_unknown` (`GridOrderExecutor.php:301`).

### C4. Minimum profitable grid spacing

| Rates (`f_b` / `f_s`) | Buy-first, sell `q` (today + spare) | Buy-first, sell `floor8(net)` | Sell-first (BTC fee at the exit price) | Sell-first, inventory-restoring buy |
|---|---|---|---|---|
| 0.25% / 0.25% (V1 + DOCS) | **0.5013%** | 0.5019% | **0.4988%** | 0.4994% |
| 0.25% / 0.35% | 0.6021% | 0.6027% | 0.5985% | 0.5991% |
| 0.35% / 0.35% (= engine assumption) | 0.7025% | 0.7037% | 0.6976% | 0.6988% |
| 0.15% / 0.15% (example of a lower maker tier, hypothetical) | 0.3005% | 0.3007% | 0.2996% | 0.2998% |

The formulas are: buy-first `s ≥ (f_b+f_s)/(1−f_s)`; net-sized `s ≥ 1/((1−f_b)(1−f_s)) − 1`; sell-first `s ≥ (f_b+f_s)/(1+f_b)`; restoring `s ≥ 1 − (1−f_b)(1−f_s)`.

What the code assumes:
- Engine and panel: break-even is `s = 2f/(1−f)` with `f = 35 bps`, which is **0.7025%**.
- `GridCalculatorService`: `2×0.25% + 0.1%` of the buy notional, which is **0.6%**.
- Validation: `MIN_SPACING = 0.5%`.

**Consequence.** At the minimum allowed spacing of 0.5%, the real outcome is roughly break-even (slightly negative for buy-first under 25/25). Under the engine's numbers it shows −0.2% per cycle. For spacings in [0.5013%, 0.7025%), the bot is truly profitable (if the DOCS sell fee holds) but books every cycle as a loss.

At 0.6% spacing on a 1.25B level, recorded is −1,276,250 per trade versus a true +1,231,250 (buy-first, 25/25). With `max_drawdown_percent` = 10 (`CreateBotConfig.php:120`) on 5B capital, about 392 such trades trip the kill switch.

### C5. Partial fills and qty_decimals truncation

- **Fees are linear in matched amount.** Three partial fills of 0.002 + 0.002 + 0.00178704 incur a total fee of 0.0000144676, the same as one fill. Partial fills therefore do not change the shortfall. They do change *when* the bot learns the amount: no pair is created until the order is Done (`handlePartialFill` docblock, `:604-614`), and `handleCanceledOrder` records partial executions but never pairs them, which **strands that BTC with no exit** (fee or not).
- **Truncation dust:** after a fee-aware `floor8(q·0.9975)`, the dust per cycle is always below 1e-8 BTC (at most ≈2,160 IRT at P). Measured examples:

| `q` | Credited | `floor8` sell | Dust |
|---|---|---|---|
| 0.00578704 | 0.0057725724 | 0.00577257 | 0.0000000024 |
| 0.00002315 | 0.000023092125 | 0.00002309 | 0.000000002125 |
| 0.000223 (real trade) | 0.0002224425 | 0.00022244 | 0.0000000025 |
| 0.000057 (real trade) | 0.0000568575 | 0.00005685 | 0.0000000075 |
| 0.00002 (real trade) | 0.00001995 | 0.00001995 | 0 |
| 0.001 / 0.0004 | 0.0009975 / 0.000399 | same | 0 |

  Dust is zero whenever `q` is a multiple of 4e-8 (because 0.0025 = 1/400). Over 100 large cycles the dust totals about 2.4e-7 BTC (≈52k IRT). It is economically negligible but **unsellable on its own**: it is below the 3M-IRT minimum and below any 1e-8 step.
- **The real fee precision is finer than `qty_decimals`.** Observed fees such as 0.0000005575 have 10 dp. The small-case fee of 0.000000057875 needs 12 dp, and how Nobitex rounds it is **UNVERIFIED** (F1/F2). Any fee-aware amount must be truncated *down* to 8 dp before sending. `placeOrder` does not truncate (§A.3), so an untruncated 10–12 dp amount would reach the API as-is (rejected or silently truncated: UNVERIFIED).
- **Planner rounding (half-up) interacts with this.** `q` itself may be 0.5e-8 above the exact value. That is irrelevant to the fee shortfall (which is about 1.4e-5 here), but it means buy amounts are not always multiples of 4e-8, so non-zero dust is the normal case.

---

## D. Risk ranking

| Rank | Severity | Problem | Trigger scenario | Depends on |
|---|---|---|---|---|
| D1 | **Money loss and failed orders** | Buy-first exit sell sized at the gross amount (`CheckTradesJob.php:933`) | Any live buy fill with free BTC below `q·0.25%`: the sell is rejected and the cycle is stuck forever. With spare BTC: 0.25% of the operator's other BTC is sold each cycle. | V1 only |
| D2 | **Failed orders and operational churn** | `InsufficientBalance` (and every other definitive `throwDomainError` code) after `$apiCallAttempted` is parked as `submission_unknown` (`CheckTradesJob.php:1116`, `GridOrderExecutor.php:301`), so the reconciler cancels, unlinks and re-pairs in a loop | D1, or any rebalance sell without BTC | F7 (exact error code) |
| D3 | **Wrong numbers that drive automation** | KillSwitch max-drawdown uses `net_profit` booked at 35 bps | Spacing in [0.50%, 0.70%): a profitable bot can be stopped | V1, D2 |
| D4 | **Money loss (slow)** | Sell-first inventory erosion is not tracked; rebalance sells are sized from IRT with no BTC check (`AdjustGridJob.php:220-226`) | Many sell-first cycles, then rebalance sells fail (D2) or consume foreign BTC | V1 |
| D5 | **Wrong numbers on screen** | `CompletedTrade` fee = 35 bps × both notionals; no real fee captured; limit price instead of average; `amount` instead of `filled_amount` | Every completed trade: about −20% net (`f_s` 0.25%) or −11% (`f_s` 0.35%) | V1, D2, D3 |
| D6 | **Sim ≠ live** | Simulation does not model fee quantities or balances | Any simulation run used to validate live readiness | V1 |
| D7 | **Wrong numbers on screen** | Panel calculator and create wizard: three inconsistent fee models; break-even shown as 0.7025% (panel) and 0.6% (wizard) | Configuring a new bot | V1, D2 |
| D8 | **Wrong numbers on screen** | `BotConfigResource` "profitable" filter subtracts the fee twice | Using the filter | none |
| D9 | **Potential failed or mis-sized orders** | `getBalances()` field names (`balance`/`blocked`) may mean "available" includes locked BTC | Initialization BTC pre-filter while other sells are open | F3 |
| D10 | **Latent** | `placeOrder` pair path does not truncate the amount | Becomes live once amounts are fee-adjusted | none |
| D11 | **Cosmetic** | `fee_bps` config key typo (`TradingEngineService.php:441`); `recordCompletedTrade` logs float numbers on `buyOrder->amount`; `estimated_fee_irt` at 35 bps; `fee_rate_percent` unused; `bot_configs.qty_decimals` unused; observer `capital_locked` +0.25% | Always | none |
| D12 | **Stranded BTC (not fee-specific)** | A canceled-with-partial-fill order is never paired | Partial fill followed by a cancel (rebalance) | none |

---

## E. Fix options (design only)

### E1. Exit-sell sizing (D1, D10). **Must be done before any live run.**

| Option | Description | Pros | Cons | Data needed |
|---|---|---|---|---|
| **E1a (recommended)** | Exit sell amount = `floor8(filled_amount − buy_fee_in_base)`, where `buy_fee_in_base` comes from order status `fee` (actual). Fallback: `ceil(filled × buy_rate)` with the configured buy rate, rounded *up* so the sell is never oversized. Leave the dust on the account. | Never oversizes. Works for partial fills. Small dust (<1e-8 per cycle). | Leg amounts become unequal. `CompletedTrade` already books on `min()`, and booking must change (E2) so that net profit is right. | REST order status `fee` (F2). Fallback: configured `buy_fee_bps`. |
| E1b | Buy `ceil8(q/(1−f_b))` so the net is at least `q`, then sell `q` | Equal legs; planner `q` is preserved | Needs the rate *before* the fill (maker/taker unknown, D3). If the actual fee is higher it is still short, so it must be combined with an E1a-style clamp. Uses slightly more IRT per level. | Configured rate, then actual fee for the clamp |
| E1c | Pre-check free BTC before placing the exit and clamp to `min(planned, free)` | Defends against every source of drift | Needs a balance call per pair (rate limits) and a correct free-balance field (F3) | `/users/wallets/list` (F3) |

**Dust:** track a per-bot `base_dust` ledger (sum of `credited − sold`). When it reaches at least 1e-8, add it to the next exit sell's amount. Alternatively, let a periodic sweep consolidate it into a rebalance sell. Never place a dust-only order (it is below the minimum notional). **Always truncate in `placeOrder`** through the same logic as `CreateOrderDto` (D10).

**Test plan:**
- Unit test `createPairOrderLocked` with a filled buy whose status has `fee`: assert the sell amount equals `floor8(filled − fee)`.
- The same test without `fee` (fallback): assert the amount is at most `floor8(filled·(1−rate))`.
- A partial-fill-then-Done sequence.
- `placeOrder` truncation of a 12-dp input.
- Regression: a filled sell produces an exit buy amount equal to the sold amount (or `ceil8(q/(1−f_b))` if E5b is adopted).

### E2. Definitive rejections versus ambiguity (D2). **Must be done before any live run.**

- Introduce a `DefinitiveOrderRejection` exception family (`InsufficientBalance`, `SmallOrder`, `BadPrice`, `InvalidMarketPair`, `TradeLimitation`, `MarketClosed`, `ParseError`). On these, the row becomes `cancelled` with `last_error_code`, plus a bot health signal. Never `submission_unknown`.
- For an exit row: keep the parent fill linked, but mark it `exit_blocked` (or similar) so `processBot` does not re-select it every minute. Retry only after a balance change or after operator action.
- **Test:** fake an `{status: failed, code: InsufficientBalance}` response and assert the row is `cancelled`, the parent is not re-paired in the next `processBot`, and the reconciler never sees it.
- **Verify on host first:** the exact `code` Nobitex returns (F7).

### E3. Fee rate configuration (D5, D7, D11). **Must be done before live, at minimum the default.**

| Option | Description | Trade-offs |
|---|---|---|
| E3a (minimum) | Change the env default to the observed rate. Set `TRADING_EXCHANGE_FEE_BPS=25` on the host now (env only, no code). Fix the `trading.fee_bps` key typo. | Fast. Still one rate for both legs and maker/taker. |
| **E3b (recommended)** | Split into `buy_fee_bps`/`sell_fee_bps` (and optionally `maker_`/`taker_`) in config. Add bot-level overrides, exposed read-only in the form. Record `fee_currency` per side (`base` for buy, `quote` for sell). | Models V1 and D2 correctly. Needs a migration and a form change. |
| E3c | Data-driven: maintain a rolling observed effective rate per side from actual fees (E4), and use the configured value only as a fallback and a sanity bound (alert if observed differs from configured by more than X bps). | Self-correcting for tier changes. Needs E4 first. |

Unify every reader on one service (for example `FeeModel::rateFor(bot, side, maker?)`): `GridPlanner`, `CompletedTrade`, `recordCompletedTrade`, `TradingEngineService`, panel `GridCalculator`, and `GridCalculatorService` (remove `NOBITEX_FEE_RATE` and the 0.1% slippage for limit orders, or label it explicitly).

**Verify on host first:** F5 (current per-bot values) and F9 (tier/maker-taker rates).

### E4. Capture real fees per order (prerequisite for E5 and E3c)

- **Schema (grid_orders):** `fee_amount DECIMAL(30,12) NULL`, `fee_currency VARCHAR(8) NULL` (`btc` or `rls`), `fee_source ENUM('actual','estimated') NULL`, `is_maker TINYINT NULL`, `avg_fill_price DECIMAL(20,0)` (keep the existing `average_fill_price`, but fill it from `averagePrice`), and `net_base_received DECIMAL(30,12) NULL` (for buys).
- **Sources, in priority order:**
  1. REST `/market/orders/status` `fee` and `averagePrice`, read in `OrderStatusDto::fromApi` (add nullable `fee` and `averagePrice` fields). This is the same call the poller already makes, so it costs nothing extra.
  2. `private:orders` WS `fee`/`avgFilledPrice` from `exchange_ws_events.payload` (already stored).
  3. `private:trades`/`trades/list` per-trade `fee` and `isMaker`, summed by `orderId` (the only source for `isMaker`; REST rows show `null`).
  4. Configured rate, marked `estimated`.
- The currency is inferred by side (buy fee in base: VERIFIED; sell fee in quote: DOCS, F8) and confirmed by magnitude (`fee/amount ≈ rate` means base; `fee/total ≈ rate` means quote).
- **Test:** `fromApi` fixtures with and without `fee`/`averagePrice`. `handleFilledOrder` persists the fee fields. A WS-payload backfill command fills orders that are missing a fee.

### E5. CompletedTrade booking (D5, D3)

- **Schema:** `buy_fee_amount`, `buy_fee_currency`, `buy_fee_irt`, `sell_fee_amount`, `sell_fee_currency`, `sell_fee_irt`, `fee_source` (`actual` / `estimated` / `mixed`), `buy_is_maker`, `sell_is_maker`, `buy_filled_amount`, `sell_filled_amount`, `base_residual` (net received minus sold, the dust), and `fee_model_version`. Keep `fee` as the total in IRT.
- **Formula, unchanged in shape:** `net = (sellAvg·sellQty − sellFeeIRT) − buyAvg·buyQty + (buyQty − buyFeeBase − sellQty)·buyAvg`, where the last term values the BTC change at the buy price. This equals `gross − buyFeeBase·buyAvg − sellFeeIRT` when `sellQty = buyQty`. Value the BTC fee at the **buy fill price** (C1 shows this makes `f_b·buyN + f_s·sellN` exact). Document the alternative of valuing at the sell price, which differs by about `f_b·q·(sell − buy)`, roughly 47k IRT on a large cycle.
- **Sell-first variant (E5b, option):** size the exit buy at `ceil8(q/(1−f_b))` so the inventory is restored. Cost: about 9k IRT less profit per large cycle (C2: 12,537,860 vs 12,546,881), but zero BTC drift. Alternatively keep the sizing and record `base_residual` as negative so the drift is visible.
- **Test:** golden-number tests using the C1/C2 tables (large and small, `f_s` 0.25/0.35), asserting stored values to the rial. Tests for unequal legs. Test that the KillSwitch reads the new `net_profit`.

### E6. Simulation fee model (D6)

Use the same `FeeModel` in `checkSimulatedOrders`: set `filled_amount = amount`, `fee_amount = amount·buy_rate` (base) or `price·amount·sell_rate` (quote), with `fee_source = estimated`, and a `net_base_received`. Pair sizing then goes through the same E1a code path. Add a **simulated wallet** per bot (IRT and BTC), seeded from config, so simulated exits can fail exactly as live exits would. A cheaper alternative is to only *log* `SIM_WOULD_FAIL_INSUFFICIENT_BASE`.

**Test:** run 100 simulated buy-first cycles with zero seed BTC and assert that exits are sized net, BTC drift is 0, and dust is below 1e-8 per cycle. With the old sizing, assert `SIM_WOULD_FAIL` on cycle 1.

### E7. Backfill of existing `completed_trades`

1. Add `fee_source` and default every existing row to `estimated_config` (with `fee_model_version = 0`). **Never silently rewrite.**
2. For live rows whose orders still appear in `trades/list` or `exchange_ws_events`, recompute with actual fees into the new columns and set `fee_source = actual`. Keep the original `profit` in a `profit_v0` column for audit.
3. For older live rows, recompute with `buy 25 bps (base) / sell configured (quote)`, mark `estimated_v1`, and show them in the UI as "estimated".
4. Simulation rows: recompute with the same model (estimated) and keep them excluded from live KPIs (verify they are already separated by `bot.simulation`; F6).
5. Before re-enabling KillSwitch drawdown, recompute `net_profit` and check that no bot would trip.

### E8. UI and consistency fixes (D7, D8, D11)

- `BotConfigResource.php:433-435`: use `SUM(profit) > 0`.
- Panel calculator: per-leg rates, a separate sell-first multiplier `(2 − s)`, and an explicit "minimum profitable spacing" read-out (C4 formulas).
- Remove or replace `GridCalculatorService::calculateTradingFees` with `FeeModel`.
- Add validation that warns when `grid_spacing` is below `FeeModel::breakEven() + margin`.

### Recommended order of implementation

| # | Item | Gate |
|---|---|---|
| 0 | Run the host checks F1–F9 (read-only) | **Before anything** |
| 1 | E3a: set `TRADING_EXCHANGE_FEE_BPS=25` on the host (env) and fix the key typo | **Must, before live** |
| 2 | E2: definitive-rejection classification | **Must, before live** |
| 3 | E1a plus `placeOrder` truncation (uses the configured-rate fallback until E4 lands) | **Must, before live** |
| 4 | E4: capture `fee`/`averagePrice` in the DTO and `grid_orders` | **Must, before live** (so E1a uses actual fees and booking is auditable) |
| 5 | E5: per-leg `CompletedTrade` booking, then re-validate KillSwitch | Strongly recommended before live (otherwise D3 can stop a profitable bot); acceptable as a follow-up only if spacing is at least 0.75% |
| 6 | E6: simulation fee model and wallet | Before using simulation as a go-live gate |
| 7 | E3b/E3c: per-side and data-driven rates | Follow-up |
| 8 | E7: backfill | After E5 |
| 9 | E8: UI unification; D9 (verify balance fields); D12 (stranded partials) | Follow-up (D9 is a must if F3 shows `blocked` is wrong) |

---

## F. Host verification checklist (read-only)

Every command is a single line inside bash double quotes, with `$` escaped and no `!` (to avoid history expansion). They print only non-secret fields: no keys, tokens or PII. Run them from the app root.

**F1. Recent BTCIRT trades: fee, isMaker, and the order ids for F2.**
```bash
ea-php83 artisan tinker --execute="foreach (array_slice(app(App\Services\NobitexService::class)->listRecentTrades('BTCIRT'), 0, 10) as \$r) { echo json_encode(array_intersect_key(\$r, array_flip(['id','orderId','type','market','amount','price','total','fee','isMaker','timestamp']))), PHP_EOL; }"
```
*Confirms:* the buy fee currency and rate (re-check of V1), `isMaker` presence, and which `orderId`s to use in F2. Also shows the fee precision (10 vs 12 dp).

**F2. Order-status shape: does it carry `fee`, `averagePrice` and `matchedAmount`?** Replace `123` with an `orderId` from F1.
```bash
ea-php83 artisan tinker --execute="\$oid = 123; \$d = (fn() => \$this->request('POST', '/market/orders/status', [], ['id' => (int) \$oid], signed: true))->call(app(App\Services\NobitexService::class)); \$o = (array) (\$d['order'] ?? []); echo json_encode(['keys' => array_keys(\$o), 'sel' => array_intersect_key(\$o, array_flip(['id','type','status','execution','amount','matchedAmount','unmatchedAmount','price','averagePrice','totalPrice','totalOrderPrice','fee','partial','isMaker','srcCurrency','dstCurrency']))]), PHP_EOL;"
```
*Confirms:* the E4 data source (REST `fee` and its currency for buys, `averagePrice` versus the limit `price`), and whether `fee` equals the sum of the per-trade fees from F1.

**F3. Wallet field names (free versus total BTC).**
```bash
ea-php83 artisan tinker --execute="\$d = (fn() => \$this->request('POST', '/users/wallets/list', signed: true))->call(app(App\Services\NobitexService::class)); foreach ((\$d['wallets'] ?? []) as \$w) { if (in_array(\$w['currency'] ?? '', ['btc','rls'], true)) { echo json_encode(['keys' => array_keys(\$w), 'currency' => \$w['currency'], 'balance' => \$w['balance'] ?? null, 'blockedBalance' => \$w['blockedBalance'] ?? null, 'activeBalance' => \$w['activeBalance'] ?? null, 'blocked' => \$w['blocked'] ?? null]), PHP_EOL; } }"
```
*Confirms:* D9. If `blocked` is null and `blockedBalance` exists, `getBalances()['available']` is the *total* balance. The output also shows how much BTC dust and spare BTC exist today.

**F4. Fee fields in the stored WS events (W3).**
```bash
ea-php83 artisan tinker --execute="foreach (Illuminate\Support\Facades\DB::table('exchange_ws_events')->orderByDesc('id')->limit(10)->get(['id','channel','status','nobitex_order_id','payload']) as \$r) { \$p = (array) json_decode(\$r->payload, true); echo json_encode(['id' => \$r->id, 'ch' => \$r->channel, 'st' => \$r->status, 'oid' => \$r->nobitex_order_id, 'keys' => array_keys(\$p), 'type' => \$p['type'] ?? (\$p['side'] ?? null), 'amount' => \$p['amount'] ?? null, 'filledAmount' => \$p['filledAmount'] ?? null, 'avgFilledPrice' => \$p['avgFilledPrice'] ?? null, 'total' => \$p['total'] ?? null, 'fee' => \$p['fee'] ?? null, 'isMaker' => \$p['isMaker'] ?? null]), PHP_EOL; }"
```
*Confirms:* the WS fee and `isMaker` availability (E4 sources 2–3) and the maker/taker split (D3).

**F5. Effective configuration and per-bot fee settings.**
```bash
ea-php83 artisan tinker --execute="echo json_encode(['cfg_fee_bps' => config('trading.exchange.fee_bps'), 'cfg_qty_dec' => config('trading.exchange.precision.BTCIRT.qty_decimals'), 'cfg_min_irt' => config('trading.min_order_value_irt'), 'bots' => App\Models\BotConfig::get(['id','name','simulation','is_active','mode','fee_bps','grid_spacing','qty_decimals','max_drawdown_percent'])->toArray()]), PHP_EOL;"
```
*Confirms:* which bots carry 35 bps, and which spacings fall into the D3 band.

**F6. Size of the backfill problem.**
```bash
ea-php83 artisan tinker --execute="echo json_encode(Illuminate\Support\Facades\DB::table('completed_trades as t')->join('bot_configs as b', 'b.id', '=', 't.bot_config_id')->selectRaw('t.bot_config_id, b.simulation, count(*) n, sum(t.profit) profit, sum(t.fee) fee, sum(t.amount) amt, min(t.created_at) first_at, max(t.created_at) last_at')->groupBy('t.bot_config_id', 'b.simulation')->get()), PHP_EOL;"
```

**F7. Has D1/D2 already happened? Find exit sells parked or cancelled, and the insufficient-balance logs.**
```bash
ea-php83 artisan tinker --execute="echo json_encode(App\Models\GridOrder::where('role', 'cycle_exit')->where('type', 'sell')->whereIn('status', ['submission_unknown','cancelled'])->latest('id')->limit(15)->get(['id','bot_config_id','status','amount','price','paired_order_id','reconcile_attempts','created_at'])), PHP_EOL;"
```
```bash
grep -h -o "Insufficient balance\|InsufficientBalance\|\"code\":\"[A-Za-z]*\"" storage/logs/*.log | sort | uniq -c | sort -rn | head
```
*Confirms:* the exact Nobitex error code for an under-funded sell (needed for E2) and whether the loop is already occurring. If no live exit has been attempted yet, the error code can only be confirmed by the manual test in F8 step 3.

**F8. Sell-fee currency (after a tiny *manual* sell on the Nobitex UI, at or above the minimum notional of about 3M IRT).**
```bash
ea-php83 artisan tinker --execute="foreach (app(App\Services\NobitexService::class)->listRecentTrades('BTCIRT') as \$r) { if (strtolower((string) (\$r['type'] ?? '')) === 'sell') { \$f = (string) (\$r['fee'] ?? '0'); echo json_encode(['id' => \$r['id'] ?? null, 'orderId' => \$r['orderId'] ?? null, 'amount' => \$r['amount'] ?? null, 'total' => \$r['total'] ?? null, 'fee' => \$f, 'fee_over_amount' => bcdiv(\$f, (string) \$r['amount'], 8), 'fee_over_total' => bcdiv(\$f, (string) \$r['total'], 8), 'isMaker' => \$r['isMaker'] ?? null]), PHP_EOL; } }"
```
*Read it as:* `fee_over_total ≈ 0.0025` means the fee is in rial (DOCS confirmed). `fee_over_amount ≈ 0.0025` means it is in BTC. Then:
1. Run F2 with that sell's `orderId` to see the REST `fee` for a sell.
2. Check the rial wallet delta (F3 before and after) to confirm.
3. Optionally, try a sell slightly larger than the free BTC to capture the `InsufficientBalance` code (it will be rejected; nothing executes).

**F9. Account fee tier and maker/taker rates (exploratory; prints fee-related keys only).**
```bash
ea-php83 artisan tinker --execute="\$d = (fn() => \$this->request('POST', '/users/profile', signed: true))->call(app(App\Services\NobitexService::class)); array_walk_recursive(\$d, function (\$v, \$k) { if (is_int(stripos((string) \$k, 'fee')) || is_int(stripos((string) \$k, 'maker')) || is_int(stripos((string) \$k, 'taker'))) { echo \$k, '=', json_encode(\$v), PHP_EOL; } });"
```
If `/users/profile` answers 405, retry with `'GET'`. Cross-check against F4 `isMaker` fills: a maker fill whose fee is not 0.25% means the tier differs by side.

---

## 3. Assumptions and unknowns

| ID | Assumption or unknown | Status | Used in | Check |
|---|---|---|---|---|
| U1 | The buy fee is charged in BTC at 0.25% of the amount | **VERIFIED** (3 live trades) | C1–C5, D1, D4 | F1 (re-confirm on newer trades) |
| U2 | The sell fee is charged in rial | DOCS, **UNVERIFIED** | C1–C4 profit numbers, E3b, E5 | F8 |
| U3 | The sell fee rate is 0.25% (0.35% shown as sensitivity) | **UNVERIFIED** | C1–C4 | F8, F9 |
| U4 | Maker vs taker rate for this account | **UNVERIFIED** (REST `isMaker` = null; the docs samples imply 0.155% and 0.175% rates on other tiers) | C4, E3c | F4, F9 |
| U5 | `/market/orders/status` returns `fee` and `averagePrice` | DOCS, **UNVERIFIED** | E1a, E4 | F2 |
| U6 | A sell exceeding free BTC is rejected with code `InsufficientBalance` (rather than, for example, an `OverValueOrder` variant) | **UNVERIFIED** (code mapping exists at `NobitexService.php:466`) | D1, D2, E2 | F7, F8 step 3 |
| U7 | Wallet fields are `balance`/`blockedBalance`/`activeBalance`, so the code's `blocked` key is wrong | **UNVERIFIED** (recollection of the docs) | D9 | F3 |
| U8 | Fee precision and rounding for fees beyond 10 dp (for example 0.000000057875) | **UNVERIFIED** | C5 | F1, F2 on a small order |
| U9 | `trades/list` history depth (how far back the backfill can use actual fees) | **UNVERIFIED** | E7 | F1 (oldest timestamp returned) |
| U10 | After initialization with `presetBaseQty`, free BTC is about 0 | Code-derived (`TradingEngineService.php:937-950`); true only if F3 shows the field semantics as assumed | C1 "no free BTC" case | F3 |
| U11 | Simulation rows are separable from live rows by `bot_configs.simulation` | Code-derived | E7 | F6 |
| U12 | Exit orders rest as makers (placed ±spacing away) | Plausible, **UNVERIFIED** | C4 rate choice | F4 |

---

## Appendix A: simulation script

Reproduce from the repo root with PHP 8.3 + bcmath: save the block below as `feesim.php` and run `php feesim.php`. It requires `app/Support/Money.php` directly (no framework boot). All values in §C are copied verbatim from its output.

```php
<?php
// Fee-audit numeric simulation. Uses App\Support\Money for all arithmetic.
require __DIR__ . '/app/Support/Money.php'; // run from repo root
use App\Support\Money as M;

const CFG_BPS = '35';           // config('trading.exchange.fee_bps') default
const QD = 8;                   // trading.exchange.precision.BTCIRT.qty_decimals

function floorq(string $v, int $d = QD): string { return M::trimZeros(bcadd($v, '0', $d)); } // positive → truncation
function r0(string $v): string { return M::round($v, 0); }
function fmt(string $v, int $d = 0): string {
    $r = M::round($v, $d);
    $neg = str_starts_with($r, '-'); if ($neg) $r = substr($r, 1);
    [$i, $f] = array_pad(explode('.', $r, 2), 2, '');
    $i = number_format((float) 0, 0) === '0' ? strrev(implode(',', str_split(strrev($i), 3))) : $i;
    return ($neg ? '-' : '') . $i . ($f !== '' ? '.' . $f : '');
}
function btc(string $v): string { return M::trimZeros(bcadd($v, '0', 12)) ?: '0'; }

// What CompletedTrade::createFromOrders books today (both legs, configured bps, limit prices, requested amount).
function recorded(string $buyP, string $sellP, string $q, string $bps = CFG_BPS): array {
    $rate  = M::div($bps, '10000');
    $gross = M::mul(M::sub($sellP, $buyP), $q);
    $fee   = M::mul($rate, M::add(M::mul($buyP, $q), M::mul($sellP, $q)));
    return ['gross' => $gross, 'fee' => $fee, 'net' => M::sub($gross, $fee)];
}

$P     = '216000000000';
$s     = '0.015';
$fb    = '0.0025';               // VERIFIED: buy fee, BTC, 0.25% of amount
$cases = ['Large (≈1,250,000,000 IRT/level)' => '1250000000', 'Small (≈5,000,000 IRT/level)' => '5000000'];
$fsSet = ['0.0025' => 'sell fee 0.25% (docs-based, UNVERIFIED)', '0.0035' => 'sell fee 0.35% (sensitivity)'];

$sellP = r0(M::mul($P, M::add('1', $s)));            // buy-first exit
$buyP2 = r0(M::mul($P, M::sub('1', $s)));            // sell-first exit

echo "P_buy=$P sell_exit=$sellP | sell-first: P_sell=$P buy_exit=$buyP2\n\n";

foreach ($cases as $label => $notional) {
    $q = floorq(M::div($notional, $P, 18)); // planner rounds half-up; for these values round==trunc+maybe 1e-8
    $q = M::trimZeros(number_format((float) M::div($notional, $P, 18), 8, '.', '')); // exact GridPlanner::formatQty behaviour
    echo "## $label: q = $q BTC (buy notional " . fmt(M::mul($P, $q)) . " IRT)\n\n";

    // ---------- C1 buy-first ----------
    $feeB     = M::mul($q, $fb);
    $credited = M::sub($q, $feeB);
    $netSell  = floorq($credited);
    $dust     = M::sub($credited, $netSell);
    echo "C1 buy-first: buy fee = " . btc($feeB) . " BTC; credited = " . btc($credited) . " BTC; bot sends SELL amount = $q (shortfall " . btc($feeB) . " BTC);\n";
    echo "   fee-aware sell = floor8(credited) = $netSell; dust = " . btc($dust) . " BTC (" . fmt(M::mul($dust, $sellP), 2) . " IRT)\n";
    $rec = recorded($P, $sellP, $q);
    echo "   recorded today: gross " . fmt($rec['gross'], 2) . " | fee " . fmt($rec['fee'], 2) . " | net " . fmt($rec['net'], 2) . "\n";
    foreach ($fsSet as $fs => $fsl) {
        // (a) today, with spare BTC covering the shortfall: sells q
        $rialA = M::sub(M::mul(M::mul($sellP, $q), M::sub('1', $fs)), M::mul($P, $q));
        $btcA  = M::sub('0', $feeB);                          // spare BTC consumed
        $trueA = M::add($rialA, M::mul($btcA, $P));           // BTC delta valued at buy price
        $trueA2 = M::add($rialA, M::mul($btcA, $sellP));      // valued at sell price
        // (b) fee-aware: sell floor8(credited)
        $rialB = M::sub(M::mul(M::mul($sellP, $netSell), M::sub('1', $fs)), M::mul($P, $q));
        $trueB = M::add($rialB, M::mul($dust, $P));           // dust kept, valued at buy price
        echo "   [$fsl]\n";
        echo "     today+spare: rial Δ " . fmt($rialA, 2) . ", BTC Δ " . btc($btcA) . " → true net " . fmt($trueA, 2) . " (@buy px) / " . fmt($trueA2, 2) . " (@sell px); recorded−true = " . fmt(M::sub($rec['net'], $trueA), 2) . "\n";
        echo "     fee-aware:   rial Δ " . fmt($rialB, 2) . ", BTC Δ +" . btc($dust) . " dust → true net " . fmt($trueB, 2) . "; recorded−true = " . fmt(M::sub($rec['net'], $trueB), 2) . "\n";
        // what the correct-rate formula would book
        $recCorr = M::sub(M::mul(M::sub($sellP, $P), $q), M::add(M::mul(M::mul($P, $q), $fb), M::mul(M::mul($sellP, $q), $fs)));
        echo "     correct-rate both-leg formula (fb·buyNotional + fs·sellNotional) = " . fmt($recCorr, 2) . "\n";
    }

    // ---------- C2 sell-first ----------
    $rec2 = recorded($buyP2, $P, $q);
    echo "C2 sell-first: sells $q @ $P, buys back $q @ $buyP2; BTC back = " . btc($credited) . " (inventory −" . btc($feeB) . " BTC)\n";
    echo "   recorded today: gross " . fmt($rec2['gross'], 2) . " | fee " . fmt($rec2['fee'], 2) . " | net " . fmt($rec2['net'], 2) . "\n";
    foreach ($fsSet as $fs => $fsl) {
        $rial = M::sub(M::mul(M::mul($P, $q), M::sub('1', $fs)), M::mul($buyP2, $q));
        $true = M::sub($rial, M::mul($feeB, $buyP2));
        // inventory-restoring buy: q/(1-fb) truncated? must be ceil to restore: ceil8(q/(1-fb))
        $restore = M::div($q, M::sub('1', $fb), 18);
        $restore8 = bcadd($restore, '0', 8); if (M::compare($restore8, $restore) < 0) $restore8 = bcadd($restore8, '0.00000001', 8);
        $rialR = M::sub(M::mul(M::mul($P, $q), M::sub('1', $fs)), M::mul($buyP2, $restore8));
        $btcR  = M::sub(M::mul($restore8, M::sub('1', $fb)), $q);
        echo "   [$fsl] rial Δ " . fmt($rial, 2) . ", BTC Δ −" . btc($feeB) . " → true net " . fmt($true, 2) . " (@exit buy px); recorded−true = " . fmt(M::sub($rec2['net'], $true), 2) . "\n";
        echo "     inventory-restoring buy amount = " . M::trimZeros($restore8) . " → rial Δ " . fmt($rialR, 2) . ", BTC Δ +" . btc($btcR) . "\n";
    }

    // ---------- C3 100 cycles ----------
    echo "C3 (100 cycles, each from a fresh grid order; BTC drift per cycle = " . btc($feeB) . "):\n";
    $drift = M::mul($feeB, '100');
    echo "   cumulative BTC drift = " . btc($drift) . " BTC = " . fmt(M::mul($drift, $P)) . " IRT @P\n";
    foreach ($fsSet as $fs => $fsl) {
        $rialA = M::sub(M::mul(M::mul($sellP, $q), M::sub('1', $fs)), M::mul($P, $q));
        $trueA = M::sub($rialA, M::mul($feeB, $P));
        $rial2 = M::sub(M::mul(M::mul($P, $q), M::sub('1', $fs)), M::mul($buyP2, $q));
        $true2 = M::sub($rial2, M::mul($feeB, $buyP2));
        echo "   [$fsl] buy-first: recorded Σ " . fmt(M::mul($rec['net'], '100')) . " vs true Σ " . fmt(M::mul($trueA, '100')) . " (err " . fmt(M::mul(M::sub($rec['net'], $trueA), '100')) . "); rial-only Σ " . fmt(M::mul($rialA, '100')) . "\n";
        echo "   [$fsl] sell-first: recorded Σ " . fmt(M::mul($rec2['net'], '100')) . " vs true Σ " . fmt(M::mul($true2, '100')) . " (err " . fmt(M::mul(M::sub($rec2['net'], $true2), '100')) . "); rial-only Σ " . fmt(M::mul($rial2, '100')) . "\n";
    }
    foreach (['0', '0.0001', '0.001', '0.01'] as $spare) {
        $n = M::compare($spare, '0') === 0 ? '0' : bcdiv($spare, $feeB, 0);
        echo "   spare free BTC $spare → buy-first exit sells succeed for " . min((int) $n, 100) . " cycle(s) before the first InsufficientBalance\n";
    }
    echo "\n";
}

// ---------- C4 break-even spacing ----------
echo "## C4 break-even spacing\n";
$rows = [['0.0025', '0.0025'], ['0.0025', '0.0035'], ['0.0035', '0.0035'], ['0.0015', '0.0015']];
foreach ($rows as [$b, $sf]) {
    $bf_gross = M::div(M::add($b, $sf), M::sub('1', $sf), 12);                          // buy-first, sell gross q, BTC fee @buy px
    $bf_net   = M::sub(M::div('1', M::mul(M::sub('1', $b), M::sub('1', $sf)), 12), '1'); // buy-first, sell net amount
    $sf_rial  = M::div(M::add($b, $sf), M::add('1', $b), 12);                           // sell-first, BTC fee @exit px
    $sf_rest  = M::sub('1', M::mul(M::sub('1', $sf), M::sub('1', $b)));                 // sell-first, inventory-restoring buy
    echo "fb=$b fs=$sf: buy-first(gross sell) " . M::round(M::mul($bf_gross, '100'), 4) . "% | buy-first(net sell) " . M::round(M::mul($bf_net, '100'), 4) . "% | sell-first " . M::round(M::mul($sf_rial, '100'), 4) . "% | sell-first restore " . M::round(M::mul($sf_rest, '100'), 4) . "%\n";
}
$f = M::div(CFG_BPS, '10000');
echo "Engine/panel model break-even (s = 2f/(1-f), f=35bps): " . M::round(M::mul(M::div(M::mul('2', $f), M::sub('1', $f), 12), '100'), 4) . "%\n";
echo "Engine model with f=25bps: " . M::round(M::mul(M::div('0.005', '0.9975', 12), '100'), 4) . "%\n";
echo "GridCalculatorService model (2×0.25% + 0.1% slippage on buy notional): 0.6%\n";

// ---------- C5 truncation + partial fills ----------
echo "\n## C5 dust per cycle (fee-aware sell = floor8(q·(1−fb)))\n";
foreach (['0.00578704', '0.00002315', '0.000223', '0.000057', '0.00002', '0.001', '0.0004', '0.00000400'] as $qq) {
    $cr = M::sub($qq, M::mul($qq, $fb)); $ns = floorq($cr); $d = M::sub($cr, $ns);
    echo "q=$qq credited=" . btc($cr) . " sell=" . $ns . " dust=" . btc($d) . "\n";
}
// partial fills: 3 fills of a 0.00578704 buy
$parts = ['0.002', '0.002', '0.00178704'];
$sumFee = '0'; foreach ($parts as $p) { $sumFee = M::add($sumFee, M::mul($p, $fb)); }
echo "partials 0.002+0.002+0.00178704: Σfee=" . btc($sumFee) . " credited=" . btc(M::sub('0.00578704', $sumFee)) . " (fees are linear → same as single fill)\n";
// max dust per cycle bound
echo "max dust per cycle < 1e-8 BTC = " . fmt(M::mul('0.00000001', $P), 0) . " IRT\n";
```
