# Fees: how the bot handles exchange fees

This is the operator's guide to fees in the BTCIRT grid bot after the fee-model work (branch `claude/wonderful-babbage-49ulfd`, phases 1–8). It replaces the single "35 bps on both legs" assumption that `docs/fee-audit.md` analysed.

**In one paragraph:** every fee rate and fee currency is decided in one place (`App\Services\FeeModel`). Every fill records the fee the exchange actually charged, the average fill price, and how much BTC the order really added or removed. An exit sell never tries to sell more BTC than the buy credited. Leftover fractions are kept in a per-bot dust ledger and folded into later sells. When the exchange definitively rejects an order, the bot cancels that order instead of retrying in a loop. Profit is booked from real per-leg fees. Simulation follows the same rules and warns where live would fail.

---

## 1. Facts the model is built on

| Fact | Status | Where it matters |
|---|---|---|
| A **buy** is charged **0.25 % in BTC** (base), deducted from the BTC received. | **Verified** on the live account (order 5566181467: fee `0.00000075` on 0.0003 BTC = sum of its 3 trade fees). | Exit sizing, booking, simulation |
| `POST /market/orders/status` returns `fee`, `averagePrice`, `totalPrice`. | **Verified** (host check V2). | Fee capture |
| Wallets return `balance`, `blockedBalance`, `activeBalance` (no `blocked`). | **Verified** (V3). | Free-balance checks |
| A **sell** is charged in **rial** (quote). | **Docs only, not verified.** | The bot *detects* the real currency on every fill and warns if it differs (§6). |
| Rates may change with tier, maker/taker. | Unknown. | Every actual fee is compared with the configured rate (§6). |

---

## 2. Where rates come from (`FeeModel`)

`FeeModel::rateFor($bot, 'buy'|'sell')` returns basis points (25 = 0.25 %), in this order of precedence:

1. **Bot override:** `bot_configs.buy_fee_bps` / `sell_fee_bps`. NULL (the default) means not overridden. These are set in the bot form under *Advanced settings*; a blank field means "use the default". A value of `0` means a zero fee and is taken literally.
2. **Config:** `trading.fees.buy_fee_bps` / `sell_fee_bps`, set by env `TRADING_BUY_FEE_BPS` / `TRADING_SELL_FEE_BPS`. Both default to **25**.

The legacy `bot_configs.fee_bps` column and the `trading.exchange.fee_bps` config key are **deprecated and not read anywhere**. They are kept only so old rows and old `.env` files stay valid.

Everything that shows or uses a fee reads `FeeModel`: the planner's `estimated_fee_irt`, booking, the buy preflight, `GridCalculatorService`, the panel calculator, the bot form and `TestNobitexApi`. `GridCalculatorService` no longer has a hard-coded 0.25 % rate or 0.1 % "slippage": grid orders are limit orders, so no slippage is modelled.

### Configuration reference (`config/trading.php` → `fees`)

| Key | Env | Default | Meaning |
|---|---|---|---|
| `buy_fee_bps` | `TRADING_BUY_FEE_BPS` | `25` | Buy rate (bps) |
| `sell_fee_bps` | `TRADING_SELL_FEE_BPS` | `25` | Sell rate (bps) |
| `buy_fee_currency` | `TRADING_BUY_FEE_CURRENCY` | `base` | Expected currency of the buy fee |
| `sell_fee_currency` | `TRADING_SELL_FEE_CURRENCY` | `quote` | Expected currency of the sell fee |
| `drift_warn_bps` | `TRADING_FEE_DRIFT_WARN_BPS` | `5` | `FEE_RATE_DRIFT` threshold |
| `classify_max_bps` | `TRADING_FEE_CLASSIFY_MAX_BPS` | `100` | Above this, a reported fee is treated as garbage (`FEE_UNCLASSIFIABLE`) |
| `fee_scale` | `TRADING_FEE_SCALE` | `10` | Decimals an *estimated* BTC fee is rounded **up** to |
| `model_version` | — | `1` | Stamped on `completed_trades.fee_model_version` |
| `restore_inventory_on_buy_exit` | `TRADING_RESTORE_INVENTORY_ON_BUY_EXIT` | `true` | Sell-first exit buys restore the BTC lost to the buy fee |
| `self_heal_min_ratio` | `TRADING_EXIT_SELF_HEAL_MIN_RATIO` | `0.98` | Exit-sell self-heal threshold (§5) |
| `exit_for_partial_cancels` | `TRADING_EXIT_FOR_PARTIAL_CANCELS` | `true` | Give a cancelled order's executed part its own exit (§4) |
| `spacing_margin_bps` | `TRADING_SPACING_MARGIN_BPS` | `10` | Safety margin above break-even for the spacing warnings |

---

## 3. Capturing the real fee on every fill

`OrderStatusDto` now carries `fee`, `averagePrice` and `totalPrice`. On every fill (the minute poller, the W4 single-order path, partial fills, and cancelled-with-partial), `FeeModel::fillFields()` writes the following to `grid_orders`:

| Column | Meaning |
|---|---|
| `fee_amount` | Cumulative fee for the order, in the currency it was charged in |
| `fee_currency` | `base` or `quote`, **detected by magnitude** (§6) |
| `fee_asset` | Raw asset code (`btc`, `rls`) |
| `fee_source` | `actual` (reported by the exchange) or `estimated` (FeeModel rate; used when no fee was reported, or for simulation) |
| `fee_quote` | The fee valued in rial (a BTC fee at this order's fill price) |
| `avg_fill_price` | Exact average execution price (`average_fill_price` keeps the whole-rial copy) |
| `net_base_delta` | Signed BTC change caused by the order, net of fees: buy = `+filled − btcFee`, sell = `−filled` (also `− btcFee` if a sell is ever charged in BTC) |

---

## 4. Sizing exits (`App\Services\ExitSizer`)

This is the critical fix. The old code sold the *gross* bought amount, but the buy fee is taken out of the BTC received, so that sell could never be fully covered.

**Buy filled → exit sell**

```
credited = filled − btcFee          (actual fee if charged in BTC; else the estimate, rounded UP)
amount   = floor_qty(credited + base_dust)
base_dust ← credited + base_dust − amount           (always in [0, 1 step))
```

- The sell is **never larger than the BTC the buy credited plus the bot's own dust**.
- `floor_qty` truncates to the market's quantity step. Every order amount, including those sent through `NobitexService::placeOrder`, is truncated **down** by `App\Support\QtyPrecision`. Nothing is ever rounded up past a balance. The step itself (BTCIRT: 6 decimals) and the price tick come from Nobitex `/v2/options` via `App\Support\MarketPrecision` — see [market-precision.md](market-precision.md).
- Dust is folded into the sell automatically once it reaches one step. A dust-only order is never placed.
- If folding a *negative* dust (a recorded shortfall, §5) would push the sell below `min_order_value_irt`, the fold is deferred (`EXIT_DUST_DEFERRED`).

**Sell filled → exit buy**

```
amount = ceil_qty(sold / (1 − buyRate))   (restore_inventory_on_buy_exit = true, default)
amount = sold                             (false — the BTC inventory then shrinks by the buy fee each cycle)
```

When that exit buy **fills**, `credited − sold` is booked into `base_dust` (`EXIT_BUY_DUST_SETTLED`).

**The dust ledger** is `bot_configs.base_dust`. Each exit row stores the dust change it caused (`grid_orders.exit_dust_delta`). If an exit intent is later cancelled and its fill unlinked (a failure before the API call, the reconciler, or a definitive rejection), the change is reverted exactly once (`EXIT_DUST_REVERTED`).

**Cancelled orders with a partial fill (audit D12).** When `exit_for_partial_cancels` is on, the executed part gets an exit through the same sizing if that exit is at least `min_order_value_irt`. A smaller executed part has its `net_base_delta` absorbed into `base_dust` (`PARTIAL_FILL_DUSTED`), and the row is marked `exit_state = 'dusted'`. This applies only to rows whose fill was captured by the fee model, so historic rows are never re-paired unexpectedly.

Every sizing logs **`EXIT_SIZED`** with `gross`, `fee`, `fee_source`, `net`, `amount`, `dust_before` and `dust_after`. The poller, W4 and simulation all use the same function.

---

## 5. Rejections: definitive vs ambiguous

| Outcome | Examples | What happens to the row |
|---|---|---|
| **Definitive**: the exchange certainly did not create the order | `InsufficientBalance`, `SmallOrder`, `BadPrice`, `InvalidMarketPair`, `MarketClosed`, `TradeLimitation`, `TradingUnavailable`, `ParseError`, `PriceConditionFailed`, or a local refusal (`LocalValidation`, e.g. a clientOrderId Nobitex would reject) | `cancelled` + `last_error_code`/`last_error_message`. **Never** `submission_unknown`. |
| **Ambiguous**: the order may exist | timeout, 5xx, dropped response, `DuplicateOrder`, `DuplicateClientOrderId` (see [client-order-id.md](client-order-id.md)) | `submission_unknown` → reconciler (unchanged) |

Definitive codes raise `App\Exceptions\DefinitiveOrderRejection` subtypes. They keep the same base class (`RuntimeException`, `InvalidArgumentException` or `DomainException`) and the same message as before, so existing catches behave the same.

**Exit sell rejected with `InsufficientBalance` → self-heal once.** The bot reads the free BTC (`activeBalance`). If it is at least `self_heal_min_ratio` (0.98) of the intended amount, the bot re-places the sell at `floor_qty(free)` and records the shortfall as negative `base_dust` (`EXIT_SELF_HEALED`). Later exits recover the shortfall.

**Otherwise, or for any other definitive code on an exit → `EXIT_BLOCKED` (CRITICAL).** The exit row is cancelled. The filled parent is unlinked and marked `grid_orders.exit_state = 'blocked'`, with `exit_blocked_reason` and `exit_blocked_at`. The bot health shows `EXIT_BLOCKED`. The fill is **no longer re-selected every minute**.

Initial and rebalance orders (`GridOrderExecutor`) follow the same split: definitive → `cancelled` + code.

### Runbook: `EXIT_BLOCKED`

1. List the blocked fills: `php artisan grid:exit-blocked` (add `--bot=ID` for one bot). It shows the fill, its fee, and the reason (exchange code and message).
2. Fix the cause:
   - **InsufficientBalance (sell):** BTC is missing from the account. Check it with `php artisan test:nobitex-api --verbose` or the panel's connection page, which show free vs total, and top up or investigate any manual withdrawal or trade.
   - **InsufficientBalance (buy):** not enough free rial. Free some, or lower the bot's capital.
   - **SmallOrder:** the exit was below the exchange minimum. Usually a tiny level; consider `--clear`.
   - **BadPrice / MarketClosed / TradeLimitation:** check the market state and the account's KYC.
3. Then either:
   - `php artisan grid:exit-blocked --retry=<fill id>` (or `--retry=all`). The next CheckTradesJob run re-sizes the exit with the current dust and places it. If it fails again, the fill is blocked again.
   - `php artisan grid:exit-blocked --clear=<fill id>`, if you handled the BTC by hand. The fill is marked `cleared` and never paired again.

The bot's `EXIT_BLOCKED` health flag clears once no blocked fills remain.

---

## 6. Detecting what the exchange really does

On every fill with a reported fee, `FeeModel::classifyActualFee()` compares two readings with the configured rate *by ratio*: `fee / amount` (the fee read as BTC) and `fee / total` (the fee read as rial). For BTCIRT the two readings differ by the BTC price (~10¹¹), so the result is unambiguous.

| Warning (channel `trading`) | Meaning | What to do |
|---|---|---|
| **`FEE_CURRENCY_UNEXPECTED`** | The detected currency differs from `trading.fees.{side}_fee_currency`. At most once per bot, side and UTC day. | See the runbook below. |
| **`FEE_RATE_DRIFT`** | The effective rate (`effective_bps`) differs from the configured rate by more than `drift_warn_bps`. At most once per bot, side and UTC day. | Your tier probably changed. Set `TRADING_{BUY,SELL}_FEE_BPS` (or the bot override) to the observed rate, run `php artisan config:clear`, and restart the queue workers. |
| `FEE_UNCLASSIFIABLE` | Neither reading is a plausible rate (or amount/total ≤ 0). The fill is recorded with an **estimated** fee. | Inspect the order on Nobitex; report it if it repeats. |

Sizing is unaffected by these warnings. The fee is always stored in the currency the exchange actually charged, and the sizer only subtracts BTC-denominated fees. So even an unexpected sell fee in BTC is handled correctly: the exit-buy target includes it and `net_base_delta` reflects it.

### Runbook: `FEE_CURRENCY_UNEXPECTED`

1. Find the warning (`grep FEE_CURRENCY_UNEXPECTED storage/logs/trading*.log`). It shows the side, expected vs detected currency, the amounts and `effective_bps`.
2. Confirm it on the exchange, using the order id from the log in the Nobitex panel or audit check F8: does `fee/total ≈ rate` (rial) or `fee/amount ≈ rate` (BTC)?
3. If it is confirmed and permanent, set `TRADING_SELL_FEE_CURRENCY=base` (or `TRADING_BUY_FEE_CURRENCY=quote`), then run `php artisan config:clear` and `php artisan queue:restart`. This aligns the *estimates* (used when a fill reports no fee, and in simulation) and the break-even read-outs with reality.
4. If it was a one-off, no action is needed. The fill was booked with the detected currency.

---

## 7. Profit booking (`CompletedTrade::createFromOrders`)

For each leg the booking uses the filled quantity (`filled_amount`), the average fill price (`avg_fill_price`) and the stored fee (actual, else an exact estimate):

```
fee   = Σ quote fees + Σ base fees × buyPx          (BTC fees valued at the BUY fill price)
gross = (sellPx − buyPx) × sellQty
net   = gross − fee
      = (sellPx·sellQty − buyPx·buyQty − Σ quote fees) + base_residual × buyPx
base_residual = buyQty − buyBaseFee − sellQty − sellBaseFee      (the dust this cycle left behind)
```

- `fee` is the total fee in rial and `net_profit` is the true net in rial. `profit` is the same as `net_profit`, stored in a whole-rial column.
- `amount` is the quantity actually round-tripped (`sellQty`). Unequal legs are normal now. Only a residual of one whole step or more logs `COMPLETED_TRADE_BASE_RESIDUAL`; this flags gross-sized (pre-fix) cycles.
- New columns: `buy_/sell_fee_amount|currency|quote`, `fee_source` (`actual`, `estimated` or `mixed`), `buy_/sell_filled_amount`, `base_residual`, `fee_model_version` (1).
- **Alternative valuation (not used):** valuing the BTC fee at the sell price instead changes net by about `buyBaseFee × (sellPx − buyPx)`, roughly 47k IRT on a 1.25B-IRT 1.5 % cycle. The buy price is used because that is what the BTC cost (`docs/fee-audit.md` §C1 shows this makes `fb·buyN + fs·sellN` exact).

The audit's golden figures are reproduced to the rial by the tests. For example, a 1.25B-IRT buy-first cycle at 1.5 % with 25/25 fees books **12,414,179.58** with fee-net sizing (the old booking showed 9,934,380.09). KillSwitch max-drawdown reads `net_profit`, so a profitable 0.6 %-spacing bot is no longer stopped (audit D3).

**Break-even spacing** (`FeeModel::breakEvenSpacing`, audit §C4) at 25/25: buy-first (fee-net sell) **0.5019 %**, sell-first (restoring buy) **0.4994 %**. The calculator and the bot form warn below *max + `spacing_margin_bps`* (0.6019 % at the defaults).

---

## 8. Simulation

Simulated fills record the same fee columns as live fills, with estimated fees. They are sized by the same `ExitSizer`, and they keep a simulated BTC position per bot: Σ `net_base_delta`, minus BTC committed to other open exit sells. If a simulated exit sell would not fit that position, **`SIM_WOULD_FAIL_INSUFFICIENT_BASE`** is logged. A live bot would get `InsufficientBalance` at the same point. The simulated order is still placed, so the simulation keeps running.

---

## 9. Balances

`NobitexService::getBalances()` returns three values per currency, as decimal strings:

- **`available`** = `activeBalance` (free)
- **`locked`** = `blockedBalance`
- **`total`** = `balance`

Before this change, "available" was the *total*, including BTC locked in open sells. If `activeBalance` is ever missing, `available` is computed as `balance − blockedBalance`, and `BALANCE_ACTIVE_FIELD_MISSING` is logged once.

---

## 10. Historic data: `fees:backfill`

| Command | Effect |
|---|---|
| `php artisan fees:backfill --dry-run` | Prints a per-bot summary (rows, Σ net now vs under the fee model, losing rows before → after, sign flips). **Writes nothing.** |
| `php artisan fees:backfill` | **Stamp only:** copies `profit`/`net_profit` into `profit_v0`/`net_profit_v0`, sets `fee_source='estimated'` and `fee_model_version=0`. Profit is unchanged. |
| `php artisan fees:backfill --apply` | Stamp, then **recompute** with the live booking code (`CompletedTrade::computeBooking`) into `profit`, `net_profit`, `fee`, `gross_profit` and the breakdown columns. Sets `fee_model_version=1`. |

The command targets only rows with `fee_model_version` NULL or 0. The originals are copied to `*_v0` **once** and never overwritten. Prices, `amount` and order links are never changed. Legacy orders carry no captured fee, so recomputed rows are always `estimated` at the current FeeModel rates. Use `--bot=ID` to limit the command to one bot.

---

## 11. Log events at a glance

| Event | Level | Source |
|---|---|---|
| `EXIT_SIZED` | info | Every exit sizing |
| `EXIT_DUST_DEFERRED` | warning | A shortfall fold was postponed |
| `EXIT_BUY_DUST_SETTLED`, `EXIT_DUST_REVERTED`, `PARTIAL_FILL_DUSTED` | info | Dust ledger moves |
| `EXIT_REJECTED` | warning | Definitive exit rejection |
| `EXIT_SELF_HEALED` | warning | InsufficientBalance self-heal succeeded |
| `EXIT_SELF_HEAL_AMBIGUOUS` | error | Self-heal retry was ambiguous (row → `submission_unknown`) |
| **`EXIT_BLOCKED`** | **critical** | Exit blocked. See §5. |
| **`FEE_CURRENCY_UNEXPECTED`**, **`FEE_RATE_DRIFT`** | warning | See §6 |
| `FEE_UNCLASSIFIABLE`, `FEE_CONFIG_INVALID`, `FEE_BOT_OVERRIDE_INVALID` | warning | Bad input or config |
| `SIM_WOULD_FAIL_INSUFFICIENT_BASE` | warning | Simulation only |
| `COMPLETED_TRADE_BASE_RESIDUAL` | warning | Booking: residual of one step or more |
| `ORDER_AMOUNT_TRUNCATED` | info | `placeOrder` truncated an amount |
| `BALANCE_ACTIVE_FIELD_MISSING` | warning | Wallet payload changed |
