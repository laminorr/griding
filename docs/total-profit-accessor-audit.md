# `BotConfig->total_profit` Accessor Audit — Why it returns 6,614 instead of 58,736

**Scope:** read-only reconnaissance. No code changed. This documents exactly what the
`total_profit` accessor computes, why it disagrees with `SUM(completed_trades.profit)`,
where the number is consumed, and the precise fix — which is **not** applied here.

**Confirmed symptom (bot 46, real host data):**
- `completed_trades` for bot 46: exactly 1 row, `profit = 58,736` (verified correct).
- `BotConfig::find(46)->total_profit` returns **6,614.5318043**.
- `getRawOriginal('total_profit')` = **0** — the DB column is literally zero, so the
  value is produced by a model **accessor**, not a stored column.

---

## 1. The accessor, verbatim

`app/Models/BotConfig.php:267-272`:

```php
public function getTotalProfitAttribute(): float
{
    return (float) ($this->completedTrades()
        ->selectRaw('COALESCE(SUM(profit - COALESCE(fee, 0)), 0) as net_profit')
        ->value('net_profit') ?? 0);
}
```

It runs `SUM(profit - COALESCE(fee, 0))` over the bot's `completed_trades` rows.

---

## 2. Exactly what it computes — and the arithmetic producing 6,614

### The root cause: `profit` is *already* net of fees, and the accessor subtracts `fee` again.

When a trade is booked, `CompletedTrade::createFromOrders()` computes a single net
figure and writes it into **both** the `profit` and the `net_profit` columns
(`app/Models/CompletedTrade.php:400-437`):

```php
// سود خالص = سود ناخالص − کارمزد دو طرف
$netProfit = Money::sub($grossProfit, $totalFee);   // CompletedTrade.php:402
...
'profit'     => $netProfit,   // CompletedTrade.php:420  ← profit IS the net figure
'fee'        => $totalFee,     // CompletedTrade.php:421
'gross_profit' => $grossProfit,// CompletedTrade.php:422
'net_profit'   => $netProfit,  // CompletedTrade.php:423  (identical to profit)
```

So for any row: `profit == net_profit == gross_profit − total_fee`.

The accessor then evaluates `profit − fee`, i.e.

```
accessor = profit − fee
         = (gross − fee) − fee
         = gross − 2·fee        ← the fee is subtracted a SECOND time
```

That is the bug: **the fee is deducted twice.**

### Arithmetic from bot 46's real data

Inputs (from the one completed trade): `buy_price = 120,918,493,600`,
`sell_price = 122,732,271,004`, `amount = 0.00006112`, `fee_bps = 35` (→ rate 0.0035).
Following `createFromOrders` (`CompletedTrade.php:380-402`):

| term | formula | value |
|------|---------|-------|
| gross_profit | `(sell − buy) × amount` | **110,858.07493248** |
| buy_notional | `buy × amount` | 7,390,538.328832 |
| sell_notional | `sell × amount` | 7,501,396.40376448 |
| total_fee | `0.0035 × (buy_notional + sell_notional)` | **52,121.77156408768** |
| net_profit → stored `profit` | `gross − total_fee` | **58,736.30336839232** ≈ **58,736** ✓ |

Now the accessor's `profit − fee`:

```
profit − fee = 58,736.30336839232 − 52,121.77156408768 = 6,614.53180430464
```

which the `(float)` cast surfaces as **6,614.5318043** — an **exact** match to the
observed value. Equivalently, `gross − 2·fee = 110,858.07493248 − 2(52,121.77156408768)
= 6,614.5318043`.

> **Note on the fractional tail.** `profit` and `fee` are declared `decimal(20,0)`
> (`database/migrations/2025_07_24_215225_create_completed_trades_table.php:22-23`).
> The observed result keeps 8 decimals, which means the host DB is not enforcing the
> `,0` scale (SQLite-style NUMERIC affinity stores the full value). This only affects
> the trailing digits — with strictly integer-rounded storage the same expression
> yields `58,736 − 52,122 ≈ 6,614`. The double-fee subtraction is the cause either way;
> `~6,614` is `gross − 2·fee` regardless of rounding.

**Ruling out the other candidates from the brief:** it is not a different column
(it reads `profit`, the correct one), not a status/date/role/`cycle_exit` filter
(there is none — it aggregates *all* of the bot's completed trades), not a per-cycle
mis-scale (no division by cycle count), and not computed from order rows (it uses
`completed_trades`). The sole defect is the extra `- COALESCE(fee, 0)`.

---

## 3. Why it differs from `SUM(completed_trades.profit) = 58,736`

`SUM(profit)` = 58,736 is the correct net total, because `profit` is already the
net figure. The accessor computes `SUM(profit − fee)`, subtracting the fee a second
time. The gap is exactly one fee:

```
58,736 (correct)  −  52,122 (fee)  ≈  6,614 (accessor)
```

The accessor is low by the total fee of the bot's trades.

---

## 4. Blast radius — everywhere `total_profit` is read

### 4a. Reads of the BotConfig **accessor** (`$model->total_profit` → the buggy 6,614)

There is no `$appends` entry and no Filament column/blade that surfaces the accessor
directly, so its only live consumers are two sibling accessors on the same model:

- `app/Models/BotConfig.php:295` — `getAvgProfitPerTradeAttribute()`:
  `$this->total_profit / $total`. **Wrong** (fee double-counted → avg understated).
- `app/Models/BotConfig.php:301` — `getTotalReturnPercentAttribute()`:
  `100.0 * $this->total_profit / $base`. **Wrong** (return % understated).
  - This one propagates further:
    - `app/Models/BotConfig.php:321` — `shouldStopLoss()` (`total_return_percent <= -stop_loss`).
    - `app/Models/BotConfig.php:328` — `shouldTakeProfit()` (`total_return_percent >= take_profit`).
    - Both risk-control checks operate on an understated return, so take-profit fires
      late and stop-loss is biased (return looks worse than reality).

### 4b. UI "total profit" tiles that do NOT use the accessor (currently correct)

These recompute directly from `CompletedTrade` and are **not** affected by the accessor bug:

- `app/Filament/Resources/BotConfigResource/Pages/ListBotConfigs.php:186` and `:222`
  — system-health notification + subheading: `CompletedTrade::sum('profit')`. Correct.

### 4c. A separate copy of the *same* double-fee bug (independent of the accessor)

- `app/Filament/Resources/BotConfigResource/Pages/EditBotConfig.php:263-265`
  — `calculateBotHealth()` runs its own `SUM(profit - fee)`. Same double-subtraction.
  Impact is low because the result is only sign-tested (`< 0` / `> 0` at lines 267/300)
  and 6,614 is still positive — but it is the same latent defect and should be fixed
  together for consistency.

### 4d. Non-consumers (mentions only)

- `app/Models/CompletedTrade.php:478,492,518` — `getPerformanceStats()` /
  `getDailyReport()` build a **return array** whose `'total_profit'` key is
  `$trades->sum('profit')` (correct). These do not read or write the bot column.
- `app/Models/User.php:55` — comment/placeholder only.
- `docs/*` — prose references.

---

## 5. Is the raw `total_profit` column ever written?

**No.** The column is created with `default(0)`
(`database/migrations/2025_10_23_000001_add_missing_columns_to_bot_configs_table.php:26`)
and there is no `update`/`save`/`fill`/`forceFill` anywhere that sets
`bot_configs.total_profit`. The only `'total_profit' =>` assignments in the codebase
(`CompletedTrade.php:478/492/518`) are keys of the stats arrays returned by
`getPerformanceStats()`/`getDailyReport()`, unrelated to the bot column. This confirms
the value is fully **accessor-driven**; the stored column correctly stays `0`
(matching `getRawOriginal('total_profit') = 0`). Nothing needs to write it.

---

## 6. Sanity check of the sibling accessors

- **`win_rate` = 100 (observed) — value correct, but built on the same double-fee filter.**
  `getWinRateAttribute()` (`BotConfig.php:286-290`) = `100 * successful_trades_count / total_trades_count`.
  `total_trades_count` (`:274-277`) is a plain `count()` — correct.
  `successful_trades_count` (`:279-284`) counts rows where
  `profit - COALESCE(fee, 0) > 0` — this reuses the **same** double-fee expression.
  For bot 46 the net-minus-fee value (6,614) is still `> 0`, so the trade is counted
  and `win_rate = 100` is *coincidentally* correct. It would be **wrong** for any trade
  where `0 < net_profit < fee` (a genuine winner mislabelled as a loss). Latent bug,
  same family, not currently visible.

- **`open_cycles_count` = 0 — correct; it is NOT an accessor.**
  It is a real stored column (`$fillable` `BotConfig.php:31`, cast `integer` `:74`,
  migration `2026_07_07_000001_add_inventory_tracking_columns_to_bot_configs_table.php:31`)
  written by `GridOrderObserver::recompute*` (`app/Observers/GridOrderObserver.php:101-141`)
  as the count of open `cycle_exit` orders. With no open cycles it is correctly 0.
  Unrelated to the profit accessor family.

**Conclusion:** the accessor *pattern* is sound. `open_cycles_count` is a correctly
maintained stored column; `win_rate` returns the right value here. The specific defect
is isolated to the `profit - fee` double-subtraction, which lives in
`getTotalProfitAttribute` (and is echoed in `successful_trades_count`'s filter and in
`EditBotConfig::calculateBotHealth`).

---

## 7. Recommended fix (specified, NOT implemented)

**Primary — `app/Models/BotConfig.php:267-272`.** Sum the already-net `profit` column;
do not subtract `fee` again:

```php
public function getTotalProfitAttribute(): float
{
    return (float) $this->completedTrades()->sum('profit');
}
```

(Equivalent explicit form: `CompletedTrade::where('bot_config_id', $this->id)->sum('profit')`.)
This returns **58,736** for bot 46 and matches the standalone `SUM(profit)` used by the
UI tiles in §4b.

**Companion fixes (same double-fee expression, fix for consistency):**
- `app/Models/BotConfig.php:282` — `successful_trades_count` should filter `profit > 0`
  (not `profit - COALESCE(fee, 0) > 0`), since `profit` is already net.
- `app/Filament/Resources/BotConfigResource/Pages/EditBotConfig.php:264` — change
  `SUM(profit - fee)` to `SUM(profit)` in `calculateBotHealth()`.

No change is needed to the raw `total_profit` column (it is intentionally accessor-driven
and correctly `0`). No migration is required.
