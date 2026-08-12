# Rebalance Bookkeeping Audit (read-only investigation)

> **Bottom line:** A rebalance executed on **1405/05/20 14:10:02**, placing **4
> `role='rebalance'` grid orders** (ids 253–256); it was triggered by the
> **10-minute `AdjustGridJob` schedule** — gated only by `is_active = true` plus
> `AdjustGridJob`'s own "price moved outside the current grid range" check
> (`app/Jobs/AdjustGridJob.php:248`), **not** by `auto_rebalance` (which does not
> exist as code anywhere) and **not** by `rebalance_threshold`; `rebalance_count`
> and `last_rebalance_at` were **NOT** updated because **no code on the rebalance
> path ever writes them** — `rebalance_count` has no increment anywhere in the
> codebase and isn't even mass-assignable, and `last_rebalance_at` is written
> only in the initial-grid path (`app/Services/TradingEngineService.php:175`);
> `grid_center_price` is a **deliberate fixed launch anchor**, because both its
> write site (`TradingEngineService.php:167-171`) and its reader
> (`app/Services/KillSwitchService.php:40-41,86`) document it as the *stable*
> Kill-Switch anchor captured once at launch and explicitly *distinct from* the
> drifting `center_price`; the Kill-Switch consequence is that stop-loss distance
> is measured from **122,759,891,986** while price is ~119B → the switch "sees"
> only **~3.0% distance** (below a typical 5% `stop_loss_percent`), which is the
> *intended* capital-basis behaviour — so `grid_center_price` is **correct**, and
> only `rebalance_count` / `last_rebalance_at` are genuine bugs.

---

## A. Where and how does rebalance trigger?

### The rebalance code path
The only production rebalance path is **`App\Jobs\AdjustGridJob`**
(`app/Jobs/AdjustGridJob.php`), scheduled every ~10 minutes:

- `routes/console.php:43-50` schedules it:
  ```php
  Schedule::job(new AdjustGridJob())
      ->name('adjust-grid')
      ->{$adjustGridCadence}()          // interval_adjust_grid, default 600s → everyTenMinutes()
      ->withoutOverlapping(20)
      ->onOneServer();
  ```

Inside `handle()` the gate sequence for each bot is:

1. **`is_active = true`** — only active bots are loaded: `AdjustGridJob.php:54`
   (`BotConfig::where('is_active', true)->get()`).
2. **Symbol whitelist** — `AdjustGridJob.php:74-91`.
3. **Kill-Switch re-check** — `AdjustGridJob.php:115` (`$killSwitch->checkAndTrigger($bot)`); if tripped, skip.
4. **Available-budget check** — `AdjustGridJob.php:158-197` (`effectiveBudget = total_capital − capital_locked_irt`; skip if ≤ 0).
5. **"Price moved outside the current grid range" check** — `AdjustGridJob.php:234-267`.
   This is the actual re-centering trigger. It compares the planner's `mid`
   price against the min/max of the bot's currently-open order prices and skips
   unless price has moved more than 50 % of the grid's own width past an edge:
   ```php
   $gridRange = $maxPrice - $minPrice;
   $threshold = $gridRange * 0.5;                 // 50% of grid range
   $distanceFromTop    = $maxPrice - $currentPrice;
   $distanceFromBottom = $currentPrice - $minPrice;
   if ($distanceFromTop > -$threshold && $distanceFromBottom > -$threshold) {
       // "Price still within grid range, skipping adjustment"
       continue;                                   // AdjustGridJob.php:248-257
   }
   ```

If it passes, it plans a fresh grid around the new mid, diffs against existing
orders, and applies:

- Plan: `AdjustGridJob.php:220-226` (`$planner->plan(...)`).
- Fetch existing: `AdjustGridJob.php:229-231` (`$reg->getOpenForBot(...)`).
- Diff: `AdjustGridJob.php:273` (`$sync->diff($plan, $existing, 1, 3.0)`).
- **Apply, stamping `role: 'rebalance'`:** `AdjustGridJob.php:276`
  (`$exec->applyForBot($bot->id, $diff, simulation: $simulate, role: 'rebalance')`).

That `role: 'rebalance'` is exactly what stamped orders 253–256
(see `app/Services/GridOrderExecutor.php:178` and `:218`, `'role' => $role`).

### How did a rebalance execute while `auto_rebalance = null`?
Because **`auto_rebalance` is not a gate — it is not referenced by any code at
all.** A repository-wide search returns **zero** hits:

- `grep -r 'auto_rebalance' app/ routes/ config/ database/` → no files found.

`auto_rebalance` is not in `BotConfig::$fillable` (`app/Models/BotConfig.php:44-57`),
not in `$casts` (`:61-101`), and not written by any migration. So the observed
`null` simply means "column that nothing reads or writes" (it either does not
exist on the table, or exists as an unused nullable). **The rebalance is gated
by `is_active` + the price-range check in `AdjustGridJob`, never by
`auto_rebalance`.** That is why a rebalance ran despite `auto_rebalance = null`.

Note also two *unused* rebalance mechanisms that might be mistaken for the gate,
but which the production path does **not** call:

- **`rebalance_threshold` (default 5.00 %)** — `database/migrations/2025_10_23_000001_add_missing_columns_to_bot_configs_table.php:23`.
  It is only consumed by `BotConfig::needsRebalance()`
  (`app/Models/BotConfig.php:310-320`), which reads a `btc_current_price` cache
  key and compares deviation from `center_price` against `rebalance_threshold`.
- **`BotConfig::needsRebalance()`** is **dead code on the trigger path**: it is
  referenced only in the model itself and in docs
  (`grep needsRebalance` → `app/Models/BotConfig.php:310` + `DEVELOPER_ONBOARDING.md`).
  `AdjustGridJob` never calls it. So the 5 % `rebalance_threshold` played no role
  in the 1405/05/20 rebalance; the 50 %-of-grid-range check
  (`AdjustGridJob.php:242,248`) is the real condition.

---

## B. Why are the counters / timestamps not updated?

### `rebalance_count` — **no increment exists anywhere**
- The column is created with `default(0)`:
  `database/migrations/2025_10_23_000001_add_missing_columns_to_bot_configs_table.php:18`
  (`$table->integer('rebalance_count')->default(0)->after('last_rebalance_at');`).
- A whole-repo search for writes/increments returns **only the migration and
  docs** — no application code touches it:
  `grep -rn 'rebalance_count'` → migration `:18`/`:39` (up/down) and
  `docs/p5-calculator-audit.md`. There is **no** `increment('rebalance_count')`,
  no `->rebalance_count =`, nothing.
- It is **also not mass-assignable**: `rebalance_count` is absent from
  `BotConfig::$fillable` (`app/Models/BotConfig.php:44-57`), so even a stray
  `update(['rebalance_count' => ...])` would be silently dropped.

**Conclusion:** `rebalance_count = 0` because nothing ever increments it. This is
a genuine bug — the counter is a permanent zero by construction.

### `last_rebalance_at` — set **only** at init, never on rebalance
- The **only** write is in the initial-grid path:
  `app/Services/TradingEngineService.php:175`
  ```php
  $botConfig->update([
      'center_price'      => $centerPrice,
      'grid_center_price' => $centerPrice,
      ...
      'last_rebalance_at' => now(),          // ← only write, happens at (re)init
      ...
  ]);
  ```
  (This whole `update()` block is `TradingEngineService.php:165-177`.)
- `AdjustGridJob` — the actual rebalance path — **never** writes
  `last_rebalance_at`. Confirm by reading `handle()` end to end
  (`AdjustGridJob.php:30-306`): after `applyForBot(... role: 'rebalance')` at
  `:276` it logs `ADJUST_GRID_BOT_COMPLETE` (`:278-281`) and moves on. No
  `BotConfig` counter/timestamp update follows.

**Conclusion:** `last_rebalance_at == started_at` (both `1405/05/10 14:23:43`)
because the field is written once, at initial grid placement, and the
1405/05/20 rebalance took a code path that omits it entirely.

### Omitted, not "branch not taken"
For all three fields (`rebalance_count`, `last_rebalance_at`, and the
center prices) the rebalance path **simply omits the updates** — there is no
conditional branch that *attempts* them and was skipped. `AdjustGridJob::handle`
contains no reference to any of these columns (verify: no `rebalance_count`,
`last_rebalance_at`, `center_price`, or `grid_center_price` token appears
anywhere in `app/Jobs/AdjustGridJob.php`). The order-placement work is delegated
to `GridOrderExecutor::applyForBot`, which creates/cancels `GridOrder` rows
(`GridOrderExecutor.php:49-295`) and **never** touches the parent `BotConfig`'s
bookkeeping. So orders 253–256 were created, but no metadata write was even
reached.

This is the same shape as the `total_profit` bug that was just fixed: the action
(placing rebalance orders) succeeds while its counters/metadata are never
persisted.

---

## C. `grid_center_price` — the Kill-Switch anchor (most important)

### What reads it
`KillSwitchService::evaluateStopLoss()` reads `grid_center_price` as the
stop-loss anchor:

- `app/Services/KillSwitchService.php:86` → `$anchor = $bot->grid_center_price;`
- Distance is `abs((current − anchor) / anchor * 100)`:
  `KillSwitchService.php:115-117`, compared to `stop_loss_percent` at `:119`.

### Should it move on rebalance? → **No. It is a deliberately fixed launch anchor.**
This is possibility **(2)** in the task, and the code states it explicitly in
two places:

1. **Writer** — `app/Services/TradingEngineService.php:167-171`:
   ```php
   // Stable Kill Switch stop-loss anchor: the mid price used for
   // planning, captured once here at (re)initialization. Distinct
   // from center_price, which drifts on later rebalances. (Phase 11
   // Step 3.)
   'grid_center_price' => $centerPrice,
   ```
2. **Reader** — `app/Services/KillSwitchService.php:40-41` (class docblock) and
   the model comment `app/Models/BotConfig.php:49`
   (`مرجع پایدار قیمت مرکز برای Kill Switch` = "stable price reference for the
   Kill Switch"):
   > "The stop-loss anchor is `bot_configs.grid_center_price` (the stable mid
   > price captured at initial grid placement), **NOT** the moving
   > `center_price`."

So `grid_center_price` is intended to be a *fixed* anchor from initial launch,
and **the rebalance correctly does not move it.** It is **not** a bug that the
1405/05/20 rebalance left it at 122,759,891,986.

> ⚠️ Caveat on the code comment's premise. The comment says `grid_center_price`
> is distinct from `center_price` "which drifts on later rebalances." In the
> *current* code, `center_price` **also** only changes at init
> (`TradingEngineService.php:166`); `AdjustGridJob` never updates `center_price`
> either (no such token in the job). That is why the host sees
> `center_price == grid_center_price == 122,759,891,986` — both frozen at
> launch. The "drift" the comment anticipates is not implemented on the
> rebalance path today. This does **not** change the verdict on
> `grid_center_price` (fixed by design = correct), but it means the design's
> distinction between the two prices is currently only aspirational.

### Kill-Switch consequence (quantified)
With anchor = 122,759,891,986 and price ~119B, the switch "sees":

```
distance% = |119.0B − 122.759891986B| / 122.759891986B × 100
          ≈ 3.06%      (at price 119.0B)
          ≈ 2.25%      (at price 120.0B)
```

The check is **symmetric** (`Money::abs(...)`, `KillSwitchService.php:110-117`) —
a move in *either* direction trips it — so it is a volatility circuit-breaker
around the launch centre, not a directional stop. At ~3 % distance it is below a
typical 5 % `stop_loss_percent`, so the switch is **not** currently tripped, and
that is the *intended* behaviour: drawdown/volatility is measured from the
original capital basis (launch centre), deliberately, so that a rebalance
chasing price cannot silently move the safety anchor with it. If the anchor
*did* re-center to ~119B on every rebalance, the stop-loss would reset its
reference to wherever price had already moved — defeating the circuit-breaker's
purpose. **Verdict: correct as-is; no fix to `grid_center_price`.**

(If, separately, the product *wants* a directional/ trailing stop that re-centers
on rebalance, that would be a design change — but the current, documented intent
is a fixed anchor, and the code matches that intent.)

---

## D. Is the rebalance itself correct? (old + new orders coexisting)

### The diff *does* schedule the stale orders for cancellation
`GridOrderSync::diff` marks every existing order that doesn't match a new plan
item as `to_cancel`, unless it is protected:

- Unmatched → cancel: `app/Services/GridOrderSync.php:105-131`
  (`$toCancel[] = $eo + ['reason' => 'not_in_plan'];` at `:130`).
- **Protection** only for `role ∈ {cycle_exit, manual}` or a set
  `paired_order_id`: `GridOrderSync.php:107-128`.

The stale far-away sells at 124–126B carry `role='initial_grid'` (stamped at
init via the same `applyForBot(... role:)` mechanism), which is **not**
protected, so the diff *would* place them in `to_cancel`.

### …but in **simulation** the cancel never touches the database
`GridOrderExecutor::applyForBot`'s cancel branch is a no-op on the DB when
`simulation = true`:

- `app/Services/GridOrderExecutor.php:75-79`:
  ```php
  if ($simulation) {
      Log::channel('trading')->info('EXEC_SIM_CANCEL', [...]);
      $cancelled++;
      continue;                       // ← no GridOrder status update
  }
  ```
  Only the **live** branch (`:81-109`) actually flips the row to `'cancelled'`
  (`:89-91`).

Meanwhile the **placement** branch *does* persist in simulation — it writes a
`status='placed'` row with `role` (`GridOrderExecutor.php:170-179`). This
asymmetry is the whole cause of the coexistence:

- **Sim placement → persisted** (new `role='rebalance'` sells become real
  `placed` rows).
- **Sim cancel → not persisted** (old `role='initial_grid'` sells stay `placed`
  in the DB).

Bot 46 is a **simulation** bot, so the old initial sells at 124–126B remain
`status='placed'` in `grid_orders` alongside the new rebalance sells at
122–124B. On every subsequent 10-minute run, `OrderRegistry::getOpenForBot`
re-reads those ghost rows (`app/Support/OrderRegistry.php:42-56`, filters
`status IN ('placed','active')`), the diff re-flags them `to_cancel`, and the
sim cancel is again a no-op — so they never clear.

> The design intent is **replace, not overlap**: the diff builds `to_cancel` for
> exactly this purpose (`GridOrderSync.php:104-131`), and the live path executes
> it. The overlap on bot 46 is therefore a **simulation-fidelity bug**
> (sim cancels don't mutate the DB the way sim placements do), *not* an intended
> overlapping-grid strategy. A live bot would have cancelled the old sells
> (`GridOrderExecutor.php:86-91`) — except in the genuinely ambiguous
> exchange-error case (`:102-109`), where orders can also transiently coexist.

### Capital / fee implication
- **Simulation (bot 46):** no real capital or fees move, so the impact is
  *accounting*, not money: the stale `placed` sells inflate the apparent
  open-order footprint and the inputs to any capital/inventory computation that
  counts `placed` rows (e.g. `capital_locked_irt` recomputation via
  `GridOrderObserver`, and the `getOpenForBot` view used by the next diff). The
  sim's own book therefore overstates deployed capital and open exposure.
- **If the same coexistence occurred live** (via the cancel-error path at
  `GridOrderExecutor.php:102-109`): real capital would be double-committed —
  budget locked behind both the stale initial sells and the new rebalance sells
  — and if both sets fill you pay maker/taker fees on redundant orders. The
  live path guards against this by only marking rows cancelled *after* the
  exchange confirms (`:86-91`), leaving unconfirmed ones for reconciliation
  (`ReconcileSubmissionsJob`), so the live risk is bounded to genuine
  cancel failures.

---

## E. Read-only verification against bot 46

I cannot run these here — this environment has no DB access and (per the task)
no `bcmath` for the drawdown math the host has. **These are SELECT-only; run on
the host.** Bot id assumed 46; adjust if needed.

### E1. Confirm the `role='rebalance'` cluster (expected: all at 1405/05/20 14:10:02)
```sql
SELECT id, type, price, amount, status, role, created_at
FROM grid_orders
WHERE bot_config_id = 46 AND role = 'rebalance'
ORDER BY created_at, id;
```
```sql
-- creation-instant grouping for every role on this bot
SELECT role, status, COUNT(*) AS n,
       MIN(created_at) AS first_at, MAX(created_at) AS last_at
FROM grid_orders
WHERE bot_config_id = 46
GROUP BY role, status
ORDER BY first_at;
```

### E2. Confirm the stale bookkeeping values
```sql
SELECT id, name, is_active, simulation, mode,
       started_at, last_rebalance_at, rebalance_count,
       center_price, grid_center_price,
       stop_loss_percent, max_drawdown_percent, rebalance_threshold
FROM bot_configs
WHERE id = 46;
```
Expected from the report: `rebalance_count = 0`,
`last_rebalance_at = started_at = 1405/05/10 14:23:43`,
`center_price = grid_center_price = 122759891986`.

### E3. Confirm old + new sells coexist (section D)
```sql
SELECT role, status, type,
       COUNT(*) AS n, MIN(price) AS min_price, MAX(price) AS max_price
FROM grid_orders
WHERE bot_config_id = 46
  AND type = 'sell'
  AND status IN ('placed','active')
GROUP BY role, status, type
ORDER BY min_price;
```
Expected: `initial_grid` sells at ~124–126B **and** `rebalance` sells at
~122–124B both present with `status='placed'`.

### E4. Drawdown % the Kill-Switch "sees" vs reality
The switch measures `abs((current − grid_center_price)/grid_center_price*100)`
(`KillSwitchService.php:110-117`). Compute it directly (host has bcmath; plain
SQL below is exact enough for a sanity read):
```sql
-- what the Kill-Switch measures against the FIXED launch anchor:
SELECT
  grid_center_price AS anchor,
  @px := <CURRENT_BTCIRT_PRICE> AS current_price,   -- e.g. 119000000000
  ABS((@px - grid_center_price) / grid_center_price) * 100 AS kill_switch_distance_pct,
  stop_loss_percent,
  CASE WHEN ABS((@px - grid_center_price) / grid_center_price) * 100 > stop_loss_percent
       THEN 'WOULD TRIP' ELSE 'ok' END AS verdict
FROM bot_configs
WHERE id = 46;
```
Tinker equivalent (uses the app's `Money` bcmath helper, matching the switch's
own math exactly):
```php
$b = \App\Models\BotConfig::find(46);
$anchor  = \App\Support\Money::normalize($b->grid_center_price);      // 122759891986
$current = \App\Support\Money::normalize('119000000000');            // set to live price
$dist = \App\Support\Money::abs(
    \App\Support\Money::mul(
        \App\Support\Money::div(\App\Support\Money::sub($current, $anchor), $anchor),
        '100'
    )
);
echo "anchor=$anchor current=$current distance_pct=$dist stop_loss={$b->stop_loss_percent}\n";
// Expected distance_pct ≈ 3.06 (at 119B) — below a 5% stop_loss → switch does NOT trip.
```
Interpretation: the switch is measuring **~3 %** from the frozen 122.7B launch
anchor while price sits at ~119B. That is the **intended** capital-basis
distance (section C), so no early/late trip is caused by the anchor being
"stale" — it is fixed on purpose. (Only if you *wanted* the switch to re-center
on rebalance would 122.7B-vs-119B look like a ~3 % error; the documented design
says it should not re-center.)

---

## Summary of findings

| Item | Verdict | Evidence |
|---|---|---|
| Rebalance trigger | `AdjustGridJob` every 10 min, gated by `is_active` + 50%-of-grid-range price move | `routes/console.php:43-50`, `AdjustGridJob.php:54,242,248` |
| `auto_rebalance` | Not a gate — token absent from entire codebase | `grep auto_rebalance` → 0 hits; not in `$fillable` (`BotConfig.php:44-57`) |
| `rebalance_threshold` (5%) / `needsRebalance()` | Dead on trigger path; never called by the job | `BotConfig.php:310-320`; not referenced in `AdjustGridJob.php` |
| `rebalance_count` | **BUG** — no increment anywhere; not mass-assignable | migration `:18`; no writes in app code; absent from `$fillable` |
| `last_rebalance_at` | **BUG** — written only at init, not on rebalance | `TradingEngineService.php:175`; absent from `AdjustGridJob.php` |
| `grid_center_price` | **Correct** — deliberate fixed launch anchor | `TradingEngineService.php:167-171`, `KillSwitchService.php:40-41,86` |
| `center_price` | Also frozen at init in current code (comment's "drift" not implemented) | `TradingEngineService.php:166`; absent from `AdjustGridJob.php` |
| Old+new orders coexist (bot 46) | **BUG (sim-fidelity)** — sim cancel is a DB no-op while sim place persists | `GridOrderExecutor.php:75-79` vs `:170-179`; diff intends replace (`GridOrderSync.php:130`) |

**Precise fixes (noted, NOT applied):**
1. In `AdjustGridJob::handle`, after a successful `applyForBot(... role:'rebalance')`
   (`AdjustGridJob.php:276`), persist the bookkeeping: `increment('rebalance_count')`
   and set `last_rebalance_at = now()` on the bot — and add `rebalance_count` to
   `BotConfig::$fillable` (`BotConfig.php:44-57`) if using mass-assignment.
2. Do **not** change `grid_center_price` on rebalance — it is correct as a fixed
   anchor (Section C). (If a trailing/re-centering stop is ever desired, that is a
   separate, deliberate design change, not this bug.)
3. Make simulation cancels mutate the DB to match live behaviour: in
   `GridOrderExecutor.php:75-79`, flip the matching local `GridOrder` row to
   `'cancelled'` in the `$simulation` branch (mirroring the live update at
   `:89-91`) so stale `initial_grid` sells don't linger for sim bots.
