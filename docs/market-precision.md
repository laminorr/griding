# Market precision: quantity step and price tick

Every order the bot sends must sit on the exchange's grid: the **amount** on the market's quantity step, and the **price** on the market's price tick. Nobitex does not reject an off-grid order. It silently truncates the amount and moves the price, so the local `grid_orders` row then no longer describes the order on the book. This page explains where the step and tick come from, how prices and amounts are rounded, and what happened on bot 48.

Code: `App\Support\MarketPrecision` (source of truth), `App\Support\QtyPrecision` (amount floor/ceil, delegates to it).

---

## 1. Source of truth

`GET /v2/options` (signed; `NobitexService::getOptionsV2()`), under `nobitex`:

| Key | Example (verified on the host) | Meaning |
|---|---|---|
| `amountPrecisions` | `{"BTCIRT":"0.000001","ETHIRT":"0.00001","USDTIRT":"0.01","BTCUSDT":"0.000001"}` | quantity step → BTCIRT **6** decimals, ETHIRT 5, USDTIRT 2 |
| `pricePrecisions` | `{"BTCIRT":"10","ETHIRT":"10","USDTIRT":"10","BTCUSDT":"0.01"}` | price tick → BTCIRT **10** rials |

`minOrders` and `tradingFees` are also in this payload but are **not** read here. The minimum order value stays `trading.min_order_value_irt`, and fees stay with `FeeModel` (see [fees.md](fees.md)).

Parsing is done with strings only, never floats: `"0.000001"` → 6 decimals, `"10"` → integer tick 10. A quantity step that is not a power of ten (`"0.0005"`, `"10"`) cannot be expressed as a decimal count, so it is ignored and logged `PRECISION_INVALID`. **A non-integer tick for an IRT symbol is invalid** because IRT prices are whole rials (`DECIMAL(20,0)`). It is ignored, logged `PRECISION_INVALID`, and that symbol falls back. A fractional tick on a USDT market (`BTCUSDT "0.01"`) is legitimate there, but this system cannot use it, so it is skipped quietly.

Symbols are canonicalised like `QtyPrecision::canonicalSymbol`: `btc-irt`, `BTCIRT` and the private-endpoint spelling `BTCRLS` all mean `BTCIRT`.

## 2. Fallback chain

```
live map (parsed /v2/options, cached 6h)
  → last-known-good map (same shape, cached forever)
    → config: trading.exchange.precision.{SYM}.qty_decimals / trading.ticks.{SYM}
```

- **An exchange outage never blocks order placement**, and `MarketPrecision` never throws. Any failure (network, signing, a malformed payload, a cache error) drops to the next step and logs **`PRECISION_FALLBACK`** (warning, channel `trading`). A failed fetch is not retried for 60 s, so an outage costs one HTTP attempt per minute, not one per order.
- A symbol that is missing from the live map uses the last-known-good value, then config. This is logged `PRECISION_FALLBACK` at most once a day.
- **Log scope:** every symbol is still parsed, but `PRECISION_INVALID` and the symbol-missing `PRECISION_FALLBACK` are only logged for symbols this system trades (`trading.exchange.allowed_symbols` plus any `bot_configs.symbol`); invalid entries for other symbols (e.g. sub-rial meme-coin IRT ticks such as `1KBONKIRT "0.001"`) are rolled into one debug line, `PRECISION_INVALID_SKIPPED {count}`, per refresh.
- When the live value differs from config, the live value is used and **`PRECISION_DRIFT`** is logged at most once per symbol per field per day. Fix config when you see it.
- The config values are **fallbacks only**, and they are kept equal to the exchange: BTCIRT 6, ETHIRT 5, USDTIRT 2 (LTCIRT unchanged at 6). All ticks are 10.
- `TRADING_PRECISION_LIVE=false` (config `trading.exchange.precision_live`) turns the live source off. The test suite does this in `phpunit.xml`, so no test can reach the network by accident. Tests that exercise the live path turn it back on with `Http::fake()`.

## 3. Rounding directions

Amounts (`QtyPrecision`):

| Where | Direction | Why |
|---|---|---|
| Grid order, exit sell, send boundary | **floor** to the step | never larger than the balance it was sized from |
| Exit buy (inventory restore) | **ceil** to the step | net BTC after the 0.25 % BTC buy fee must reach the restore target; the overshoot goes to `base_dust` when the buy fills |

Prices (`MarketPrecision::roundPrice($price, $symbol, $side)`, exact bcmath with no float round-trip):

| Side | Direction | Why |
|---|---|---|
| Buy (grid level, exit buy) | **down** to the tick | never pay more; the realised spread is never below `grid_spacing` |
| Sell (grid level, exit sell) | **up** to the tick | never sell cheaper; same guarantee |

`GridPlanner`, `AdjustGridJob` and `GridOrderExecutor` all use the same helper (`MarketPrecision::alignToTick`). The exit path in `CheckTradesJob::createPairOrderLocked` uses `roundPrice`.

**The row is what was sent.** The amount and price are fitted **before** the `grid_orders` intent row is written: by the planner and executor on the grid path, and by `ExitSizer` and `roundPrice` on the exit path. The send boundary (`CreateOrderDto::toApiPayload`, `NobitexService::placeOrder`) fits them again as a safety net. If a price reaches it off-tick, it is rounded side-safely and **`ORDER_PRICE_ROUNDED`** is logged. That should never fire. If it does, a caller skipped the alignment.

## 4. The bot-48 incident (BTCIRT, live)

- Config said `qty_decimals = 8`, but the real step is 6. Grid orders went out with amount `0.00004504`. Nobitex stored `0.000045`, and the DB kept `0.00004504`.
- The exit price was rounded only to the whole rial (`FeeModel::roundHalfUp(…, 0)`), not to the tick. Exit buy 279 was computed at `221949050136`, Nobitex stored `221949050140`, and the DB kept `…136`.
- The restore-buy for 279 was sized `0.00004512` (8 dp) to cover the 0.25 % BTC buy fee. Nobitex truncated it to `0.000045`, so the restore came up short.

With the fix, the same fill produces:

```
sell filled 0.000045 @ 225328984910, fee 25349.510802375 IRT, grid_spacing 1.50
exit buy price  = floor_tick(225328984910 × 0.985 = 221949050136.35) = 221949050130
restore amount  = ceil6(0.000045 / 0.9975 = 0.0000451127…)           = 0.000046
on fill: fee 0.000000115 BTC → credited 0.000045885 − 0.000045 → base_dust +0.000000885
```

(Regression test: `tests/Feature/Fees/MarketPrecisionExitTest.php`.)

### Repairing rows placed before the fix: `grid:sync-precision`

```
php artisan grid:sync-precision 48           # dry-run: diff table, nothing written
php artisan grid:sync-precision 48 --apply   # write the exchange amount/price; logs PRECISION_ROW_SYNCED per row
```

For each **open** row (`placed`, `partially_filled`) that has a real `nobitex_order_id`, the command reads `POST /market/orders/status` and compares the exchange's `amount` and `price` with the local row. It never touches FILLED, cancelled or `SIM-*` rows, `filled_amount`, fees, or `completed_trades`. It never cancels, replaces or places an order. Expected on bot 48: rows 275/276/277 amount → `0.000045`; row 279 amount → `0.000045` and price → `221949050140`.

Row 279 stays on the book as it is. When it fills, the next exit sell is sized from its **actual** net fill, so there is no balance shortfall. Inventory is short by one 0.25 % fee on that one cycle, and that shows up in the dust ledger.
