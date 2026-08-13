# Rebalance Trigger Audit — does the bot rebalance too early?

> **Bottom line:** The rebalance trigger measures the grid range from **the
> MIN/MAX price of the bot's currently‑OPEN orders only (status `placed`/`active`)** —
> a band that shrinks and goes one‑sided as one side of the grid fills. For bot 46
> it saw **min = 124,601,290,370, max = 126,470,309,730, range = 1,869,019,360,
> threshold = 934,509,680** and **DID fire** at price ~121B (the price sat
> ~3.60B below the open band's floor, far beyond the 0.93B threshold). Had it
> measured from the full original grid (min = 119,104,716,200, max =
> 126,470,309,730, range = 7,365,593,530, threshold = 3,682,796,765) it
> **would NOT have fired** — at ~121B the price was still *inside* the original
> grid (above the 119.1B floor). **This is a bug**, and the correct fix is to
> measure the range from the **full planned grid** — the min/max of *all*
> `role=initial_grid` orders (filled + open), equivalently `grid_center_price`
> ± the reconstructed half‑width — instead of the surviving open orders.

This report is read‑only reconnaissance. No code was changed. Every claim is
quoted to `file:line`.

> **Note on the referenced prior audit.** `docs/rebalance-bookkeeping-audit.md`
> is *not present* in the current tree (checked working tree and
> `git log --all -- docs/rebalance-bookkeeping-audit.md`; the bookkeeping fix
> landed as commit `e688492` / PR #163, `docs/` files present are
> `profit-accounting-audit.md`, `phase13-investigation.md`, `p5-calculator-audit.md`).
> Its description of the trigger — `AdjustGridJob.php` ~:234‑267 comparing the
> live price against MIN/MAX of the bot's open‑order prices with a 50%‑of‑range
> tolerance — matches the current code exactly, so the analysis below is drawn
> from the code itself.

---

## A. What EXACTLY defines the "grid range" the trigger uses?

### The range‑check block — `app/Jobs/AdjustGridJob.php:228‑267`

```php
// ✅ Get existing orders using bot-specific method
$existingOrders = method_exists($reg, 'getOpenForBot')
    ? $reg->getOpenForBot($bot->id, $symbol)          // :229-231
    : $reg->getOpen($symbol);

// ✅ Only adjust grid if price moved significantly outside current grid range
if (!empty($existingOrders)) {                         // :234
    $currentPrice = (int) ($plan['mid'] ?? 0);         // :235
    $prices = array_column($existingOrders, 'price');  // :236
    $minPrice = min($prices);                          // :237
    $maxPrice = max($prices);                          // :238

    // Calculate grid range
    $gridRange = $maxPrice - $minPrice;                // :241
    $threshold = $gridRange * 0.5;  // 50% of grid range  :242

    // Check if current price is still within acceptable range
    $distanceFromTop = $maxPrice - $currentPrice;      // :245
    $distanceFromBottom = $currentPrice - $minPrice;   // :246

    if ($distanceFromTop > -$threshold && $distanceFromBottom > -$threshold) {  // :248
        Log::channel('trading')->info('AdjustGridJob: Price still within grid range, skipping adjustment', [ ... ]);  // :249
        continue;   // :256  (SKIP — no rebalance)
    }

    Log::channel('trading')->info('AdjustGridJob: Price moved outside grid range, proceeding with adjustment', [ ... ]);  // :259
}
// falls through to plan/diff/apply → rebalance   (:269-292)
```

### The range is built from OPEN orders — not a stable source

- **`$minPrice` / `$maxPrice` (`:237‑238`)** come from `array_column($existingOrders, 'price')`
  (`:236`). `$existingOrders` is the result of
  `OrderRegistry::getOpenForBot($bot->id, $symbol)` (`AdjustGridJob.php:229‑231`).

- **`getOpenForBot` returns only open orders** — `app/Support/OrderRegistry.php:42‑56`:

  ```php
  public function getOpenForBot(int $botId, string $symbol): array
  {
      return \App\Models\GridOrder::where('bot_config_id', $botId)
          ->whereIn('status', ['placed', 'active'])   // :45  ← ONLY open statuses
          ->get()
          ->map(fn($order) => [
              'id' => $order->nobitex_order_id,
              'side' => $order->type,
              'price' => (int) $order->price,
              ...
          ])->toArray();
  }
  ```

  The `whereIn('status', ['placed', 'active'])` filter (`OrderRegistry.php:45`) is
  the crux: **filled orders drop out**. There is **no role filter** — it takes
  every open row for the bot regardless of `role` (`initial_grid`, `rebalance`,
  `cycle_exit`) — but the status filter alone is enough to remove filled grid
  legs from the min/max. As one side of the grid fills, those prices vanish from
  the band, so the band **narrows and one‑sides**.

- **The comparison is against the live price**, not a stored center.
  `$currentPrice = (int)($plan['mid'] ?? 0)` (`:235`). `$plan` comes from
  `$planner->plan(...)` (`AdjustGridJob.php:220‑226`) with no `$lastPrice`
  argument, and `GridPlanner::plan` sets
  `$mid = $lastPrice ?? $this->md->getLastPrice($symbol)`
  (`app/Services/GridPlanner.php:79`). So `$currentPrice` is the **current market
  price** from `MarketData::getLastPrice`.

- **There is NO stable source in this block.** Nothing here references
  `grid_center_price`, `center_price`, `grid_levels × grid_spacing`, or the full
  planned grid width. The "grid range" is *entirely* defined by the prices of the
  orders that happen to still be open at trigger time.

### What the `:248` comparison actually means

`$distanceFromTop = maxPrice − price`, `$distanceFromBottom = price − minPrice`,
`$threshold = 0.5 × (maxPrice − minPrice)`. The **skip** condition is:

```
(maxPrice − price) > −threshold  AND  (price − minPrice) > −threshold
```

which rearranges to the acceptable (no‑rebalance) zone:

```
minPrice − threshold  <  price  <  maxPrice + threshold
```

i.e. the open‑order band **padded by 50% of its own width on each side**. Price
must exit that padded band for a rebalance to fire. Because the band itself is
the *shrunken open‑order* band, the padding is also computed off the shrunken
range — so the whole tolerance collapses as orders fill.

---

## B. Reproduce the trigger with bot 46's real numbers

At rebalance time (1405/05/20 ~14:10), the four `role=initial_grid` orders were:

| side | status  | price (IRT)      | note |
|------|---------|------------------|------|
| buy  | FILLED  | 119,104,716,200  | grid floor (dropped from band) |
| buy  | FILLED  | 120,918,493,600  | (dropped from band) |
| sell | placed  | 124,601,290,370  | open |
| sell | placed  | 126,470,309,730  | open — grid ceiling |

Only the two **sells** were open, so `getOpenForBot` returned just those two.

### B1 — What the trigger actually saw (open‑order band)

```
minPrice   = 124,601,290,370
maxPrice   = 126,470,309,730
gridRange  = 126,470,309,730 − 124,601,290,370 = 1,869,019,360
threshold  = 0.5 × 1,869,019,360             =   934,509,680

price ≈ 121,000,000,000
distanceFromTop    = 126,470,309,730 − 121,000,000,000 =  5,470,309,730
distanceFromBottom = 121,000,000,000 − 124,601,290,370 = −3,601,290,370
```

Evaluate the skip condition at `:248`:

```
distanceFromTop    > −threshold :  5,470,309,730 > −934,509,680  → TRUE
distanceFromBottom > −threshold : −3,601,290,370 > −934,509,680  → FALSE
TRUE && FALSE = FALSE   →  does NOT skip  →  REBALANCE FIRES ✅ (matches observed)
```

Equivalently, the acceptable zone was
`[minPrice − threshold, maxPrice + threshold]` =
`[123,666,780,690, 127,404,819,410]`. The price 121B fell **2,666,780,690 below**
the lower edge (123.67B). **Any price below ~123.67B would have triggered** — even
though the true grid floor is 119.1B, roughly **4.56B lower**.

### B2 — What it SHOULD have seen (full original grid)

Include the filled buys, so min/max span the whole planned grid:

```
minPrice   = 119,104,716,200   (grid floor)
maxPrice   = 126,470,309,730   (grid ceiling)
gridRange  = 126,470,309,730 − 119,104,716,200 = 7,365,593,530
threshold  = 0.5 × 7,365,593,530             = 3,682,796,765

price ≈ 121,000,000,000
distanceFromTop    = 126,470,309,730 − 121,000,000,000 =  5,470,309,730
distanceFromBottom = 121,000,000,000 − 119,104,716,200 =  1,895,283,800
```

Evaluate `:248`:

```
distanceFromTop    > −threshold :  5,470,309,730 > −3,682,796,765 → TRUE
distanceFromBottom > −threshold :  1,895,283,800 > −3,682,796,765 → TRUE
TRUE && TRUE = TRUE   →  SKIP  →  NO REBALANCE ✅
```

Acceptable zone `[115,421,919,435, 130,153,106,495]`; 121B sits comfortably
inside. In fact the simplest statement: **121B is above the grid floor of
119.1B, so the price never left the original grid at all** — a correct trigger
skips.

### "How early" it fired

| Measure           | Open‑order band (bug) | Full original grid (correct) |
|-------------------|-----------------------|------------------------------|
| min               | 124,601,290,370       | 119,104,716,200              |
| max               | 126,470,309,730       | 126,470,309,730              |
| range             | 1,869,019,360         | 7,365,593,530                |
| threshold (0.5×)  | 934,509,680           | 3,682,796,765                |
| lower fire edge   | **123,666,780,690**   | 115,421,919,435              |
| decision @ ~121B  | **REBALANCE**         | **SKIP**                     |

The bug moved the lower fire edge up from **115.42B to 123.67B** — an ~8.25B
(≈6.7%) upward shift of the trigger boundary. The bot rebalanced with the price
still ~2.67B *inside* the original grid.

---

## C. Is this intended or a bug?

**It is a bug (an oversight), not deliberate design.**

- **No code or comment claims the open‑order band is the intended range.** The
  only comments in the block are `// Calculate grid range` (`:240`) and
  `// 50% of grid range` (`:242`) — they describe the arithmetic, not a design
  intent to measure from *surviving* orders. The surrounding `// ✅` comments
  (`:228`, `:233`) read as "get orders / only adjust if outside range," i.e. the
  author's mental model is "is price outside *the grid*," and open orders were
  used as a convenient stand‑in for "the grid." Nothing signals awareness that
  fills shrink the band.

- **A stable anchor exists and is used elsewhere — but not here.**
  `bot_configs.grid_center_price` is captured once at initial placement and is
  explicitly documented as the STABLE reference:
  - Set at init: `TradingEngineService.php:171` — `'grid_center_price' => $centerPrice`,
    with the comment (`:167‑170`) "Stable Kill Switch stop‑loss anchor: the mid
    price used for planning, captured once here at (re)initialization. Distinct
    from center_price, which drifts on later rebalances."
  - Migration `database/migrations/2026_07_07_000002_add_grid_center_price_to_bot_configs_table.php:16‑21`
    contrasts the moving `center_price` with the stable `grid_center_price`.
  - **Used by the Kill Switch** as the stop‑loss anchor:
    `KillSwitchService.php:40‑41` ("The stop‑loss anchor is
    bot_configs.grid_center_price … NOT the moving center_price") and
    `KillSwitchService.php:86` `$anchor = $bot->grid_center_price;`.
  - The rebalance path (`AdjustGridJob::handle`) **never reads
    `grid_center_price`** (grep: it appears only in `TradingEngineService`,
    `KillSwitchService`, `BotConfig`, and Filament pages — not in
    `AdjustGridJob.php`). So the exact stable anchor that *should* define the
    grid center for the trigger is available on the bot but ignored.

- **`grid_center_price` is frozen for the rebalance path.** It is written only
  inside `TradingEngineService::initializeGrid` (`:171`). `AdjustGridJob` plans
  via `GridPlanner` and applies via `GridOrderExecutor` with `role: 'rebalance'`
  (`AdjustGridJob.php:283`) — it does not call `initializeGrid`, so
  `grid_center_price` stays at the original ~122.7B center across rebalances.
  This is exactly the stable center the trigger could anchor to.

- **The "old" deviation‑based rebalance check is a stable design that is not
  wired up.** `BotConfig::needsRebalance()` (`app/Models/BotConfig.php:310‑320`)
  measures `abs(currentPrice − center_price)/center_price` against
  `rebalance_threshold` — a *center‑anchored* test that would not shrink with
  fills. But it has **no callers** in application code (grep for `needsRebalance`
  hits only its definition and `DEVELOPER_ONBOARDING.md:113`). So the intended
  center‑anchored idea exists in the model but the *live* trigger is the
  open‑order band in `AdjustGridJob`, confirming the band test was a later,
  simpler substitute that lost the stable center.

### Consequence — the failure mode

Yes, this makes the bot **rebalance too frequently / too early**, producing
churn (extra cancels + replacements = extra fees on a live bot) whenever one
side fills and price drifts modestly. Precisely:

> After the buy side fills (price drifted down and bought the dip — the grid
> working as designed), only the sells remain open, forming a narrow one‑sided
> band `[sell_low, sell_high]` *above* the current price. `distanceFromBottom =
> price − sell_low` is already strongly negative, and the threshold is only
> `0.5 × (sell_high − sell_low)` — a fraction of the *remaining* band, not of the
> full grid. So **any downward move of more than `0.5 × (remaining‑sell‑band
> width)` below the lowest still‑open sell triggers a rebalance, even though the
> price is mid‑original‑grid.** Symmetrically, after sells fill (price rose), the
> surviving buy band sits below price and any upward drift past
> `0.5 × (remaining‑buy‑band width)` above the highest open buy triggers. The
> more the grid fills on one side, the narrower the band and the more hair‑trigger
> the rebalance — the exact opposite of the intended "only rebalance when price
> truly leaves the grid."

Because each rebalance cancels the surviving orders and re‑places a fresh grid
around the new (lower) price, and the fresh grid will again fill on one side and
re‑trigger, this can cascade into repeated rebalances during an ordinary trend —
churning fees while the price is still within what the *original* grid was built
to trade.

---

## D. What SHOULD the trigger measure? (specification only — not implemented)

### Recommendation

**Measure the range from the FULL planned grid, anchored on the stable center,
and require the price to exit the ORIGINAL grid bounds — not the shrunken
open‑order band.** Concretely, prefer option **(iii)** implemented via the
persisted `initial_grid` rows, with option **(ii)** (`grid_center_price` ±
reconstructed half‑width) as the robust fallback. Rationale below.

Candidates evaluated:

- **(i) Full planned grid width = levels × spacing around a stable center.**
  Correct in spirit. The bot stores `grid_levels` and `grid_spacing`
  (`AdjustGridJob.php:222‑223` reads `$bot->grid_levels`, `$bot->grid_spacing`),
  and the geometry is `GridPlanner`'s `pow(1 ± step, i)` (`GridPlanner.php:98,108`).
  So the full half‑widths can be reconstructed from `grid_center_price`:
  `upper = grid_center_price × (1 + step)^perSide`,
  `lower = grid_center_price × (1 − step)^perSide`, with
  `perSide = intdiv(levels, 2)` for `both` (`GridPlanner.php:84`). Downside: it
  recomputes the grid rather than using the actual placed prices, and must mirror
  the tick‑rounding (`roundToTick`, `GridPlanner.php:227‑238`) to match exactly.

- **(ii) `grid_center_price` ± half‑grid‑width.** Same as (i) but names the
  stable anchor explicitly. `grid_center_price` is the right center (frozen at
  init, `TradingEngineService.php:171`). The *width* is **not** stored as a single
  column, so it still has to be reconstructed from `grid_levels`/`grid_spacing`
  as in (i). Good robust fallback when no `initial_grid` rows survive.

- **(iii) Require price to exit the ORIGINAL grid bounds (recommended).** Take
  min/max over **all `role=initial_grid` orders regardless of status** (filled +
  open), not just open ones. This recovers exactly `119,104,716,200` /
  `126,470,309,730` for bot 46 without any geometric reconstruction, because those
  rows are persisted in `grid_orders` and never deleted on fill (they transition
  to `filled`). It is the smallest, most faithful change: swap the
  `whereIn('status', ['placed','active'])` band (`OrderRegistry.php:45`) for a
  `where('role','initial_grid')` query (all statuses) when computing min/max for
  the *trigger* — while the diff/apply step keeps using open orders as it does
  today (`AdjustGridJob.php:270,273`).

**Why (iii) is best:** it uses the real, historically‑placed grid edges (no
float‑pow reconstruction, no tick‑rounding to re‑derive), it is stable across
fills, and it directly expresses the intended semantics — "rebalance only when
price leaves the grid the bot actually built." Use (ii)/(i) only as a fallback
when `initial_grid` rows are unavailable.

### Edge cases to handle in the fix

- **Grid never fully placed** (some `initial_grid` orders failed at init).
  Min/max over the `initial_grid` rows that *did* persist is still far better than
  the open‑only band; if zero `initial_grid` rows exist, fall back to (ii):
  `grid_center_price` ± reconstructed half‑width.
- **One‑sided modes (`buy` / `sell`).** The grid is legitimately one‑sided, so a
  one‑sided band is expected there — but the correct edge is still the full
  *planned* extent on the deployed side, anchored at `grid_center_price`, not the
  surviving open orders. Reconstruct the single‑sided width from
  `grid_center_price` and `grid_levels`/`grid_spacing`; do not let a partially
  filled one‑sided grid collapse the band to a hair‑trigger.
- **`grid_center_price` present but width not stored anywhere.** Correct — there
  is no width column. Reconstruct from `grid_levels` × `grid_spacing`
  (`AdjustGridJob.php:222‑223`) using the same geometry/tick rounding as
  `GridPlanner` (`GridPlanner.php:98,108,227‑238`). This is the main reason to
  prefer (iii): the persisted `initial_grid` prices already encode the
  tick‑rounded width, so no reconstruction is needed.
- **After a legitimate rebalance**, `initial_grid` rows still describe the
  *original* grid, while new orders carry `role=rebalance`. Decide whether the
  trigger should track the original grid indefinitely or re‑anchor on the most
  recent (re)initialization. Cleanest: re‑stamp `grid_center_price` (and, if
  adopted, a stored width) whenever a rebalance re‑centers the grid, so the
  "current grid bounds" always reflect the live grid — otherwise option (iii) must
  scope to the *latest* generation of grid orders, not literally `initial_grid`
  forever.

### Does the fix resolve bot 46 without breaking real exits?

- **Fixes the early trigger:** with the full original grid
  (min 119,104,716,200 / max 126,470,309,730, threshold 3,682,796,765), price
  ~121B yields `distanceFromBottom = 1,895,283,800 > −3,682,796,765 → TRUE` and
  `distanceFromTop = 5,470,309,730 > −3,682,796,765 → TRUE` → **SKIP** (§B2). Bot
  46 would **not** have rebalanced at 121B.
- **Still rebalances on a genuine breakout:** the skip zone becomes
  `[115.42B, 130.15B]`. A price below ~115.42B or above ~130.15B — i.e. more than
  half a *full* grid past an edge — still fires, exactly as intended. The fix only
  removes the spurious fires that came from the band collapsing after fills.

---

## E. Read‑only verification for the host (host has bcmath; agent does not)

The agent did not compute on the host or read logs; the following are
**SELECT‑only** / read‑only steps for the host to confirm the numbers and the
exact decision.

> `grid_orders` columns used below (from `app/Models/GridOrder.php:11‑20` and the
> `getOpenForBot` query, `OrderRegistry.php:44‑45`): `bot_config_id`, `type`
> (side), `price` (DECIMAL(20,0)), `status` (open = `placed`/`active`), `role`
> (`initial_grid` / `rebalance` / `cycle_exit`), `filled_at`, `created_at`.
> Timestamps are Gregorian; 1405/05/20 (Jalali) ≈ 2026‑08‑11.

### E1 — The open band the trigger actually saw (min/max of open orders)

```sql
-- Open orders for bot 46 = exactly what getOpenForBot() returned.
-- Their MIN/MAX price is the band the trigger measured.
SELECT id, type, status, role, price, created_at, filled_at
FROM grid_orders
WHERE bot_config_id = 46
  AND status IN ('placed','active')
ORDER BY price;

-- The band + threshold the trigger used:
SELECT MIN(price)                      AS grid_min,
       MAX(price)                      AS grid_max,
       MAX(price) - MIN(price)         AS grid_range,
       (MAX(price) - MIN(price)) * 0.5 AS threshold
FROM grid_orders
WHERE bot_config_id = 46
  AND status IN ('placed','active');
-- Expected (per §B1): grid_min=124,601,290,370  grid_max=126,470,309,730
--                     grid_range=1,869,019,360   threshold=934,509,680
```

### E2 — The FULL original grid (what the fix would measure)

```sql
-- All original grid legs, filled + open. MIN/MAX = true grid bounds.
SELECT id, type, status, price, filled_at
FROM grid_orders
WHERE bot_config_id = 46
  AND role = 'initial_grid'
ORDER BY price;

SELECT MIN(price)                      AS full_min,
       MAX(price)                      AS full_max,
       MAX(price) - MIN(price)         AS full_range,
       (MAX(price) - MIN(price)) * 0.5 AS full_threshold
FROM grid_orders
WHERE bot_config_id = 46
  AND role = 'initial_grid';
-- Expected (per §B2): full_min=119,104,716,200  full_max=126,470,309,730
--                     full_range=7,365,593,530   full_threshold=3,682,796,765
```

### E3 — The stable anchor on the bot

```sql
SELECT id, center_price, grid_center_price, grid_levels, grid_spacing,
       mode, rebalance_threshold, last_rebalance_at, rebalance_count
FROM bot_configs
WHERE id = 46;
-- grid_center_price should be ~122.7B (frozen at init, TradingEngineService.php:171)
-- and is the anchor the fix (option ii) would use if initial_grid rows are absent.
```

### E4 — Read the exact price and decision from the trading log (do NOT guess)

`AdjustGridJob` logs the decision to the **`trading`** channel with these exact
message keys (`AdjustGridJob.php:249` and `:259`):

- Skip: `AdjustGridJob: Price still within grid range, skipping adjustment`
  with payload `current_price`, `grid_min`, `grid_max`, `threshold`
  (`AdjustGridJob.php:249‑255`).
- Fire: `AdjustGridJob: Price moved outside grid range, proceeding with adjustment`
  with payload `current_price`, `grid_min`, `grid_max`, `distance_from_top`,
  `distance_from_bottom` (`AdjustGridJob.php:259‑266`).

The upstream plan also logs the mid it used: key `GRID_PLAN` with a `mid` field
(`GridPlanner.php:222`). The per‑bot start logs config: key `ADJUST_GRID_BOT_START`
(`AdjustGridJob.php:127‑135`).

On the host, read the `trading` log channel around 1405/05/20 14:10
(≈ 2026‑08‑11 in Gregorian) — e.g. the file configured for
`Log::channel('trading')` under `config/logging.php` (commonly
`storage/logs/trading-*.log`):

```bash
# Confirm the exact price and the min/max the trigger saw at rebalance time:
grep -nE "AdjustGridJob: Price (still within|moved outside) grid range" \
     storage/logs/trading-*.log | grep -A0 "bot.*46"

# Or pull the structured line for bot 46 and read current_price / grid_min / grid_max:
grep -n "\"bot_id\":46" storage/logs/trading-*.log | grep "grid range"

# The mid the planner used at that moment:
grep -n "GRID_PLAN" storage/logs/trading-*.log | grep "\"mid\""
```

The `current_price` in the "proceeding with adjustment" line is the authoritative
price at the rebalance moment (≈121B is inferred from the new grid center; the log
is the source of truth). `grid_min`/`grid_max` in that same line should equal the
open‑band values from E1 (124,601,290,370 / 126,470,309,730), confirming the
trigger measured the shrunken open band and fired while the price was still inside
the original grid.

---

## Summary

| Question | Finding | Evidence |
|----------|---------|----------|
| What defines the range? | MIN/MAX of **open orders only** (`placed`/`active`), padded 50% | `AdjustGridJob.php:236‑248`, `OrderRegistry.php:42‑56` |
| Price source | live `getLastPrice` via `plan['mid']` | `AdjustGridJob.php:235`, `GridPlanner.php:79` |
| Bot 46 open band | min 124.60B / max 126.47B / range 1.869B / thr 0.935B → **FIRED** at ~121B | §B1 |
| Bot 46 full grid | min 119.10B / max 126.47B / range 7.366B / thr 3.683B → **SKIP** at ~121B | §B2 |
| Intended? | **Bug** — stable `grid_center_price` exists but is ignored by the trigger | `TradingEngineService.php:171`, `KillSwitchService.php:40‑41,86`, `needsRebalance` unused (`BotConfig.php:310`) |
| Correct fix | Measure full planned grid: min/max of all `role=initial_grid` rows (opt iii), or `grid_center_price` ± reconstructed half‑width (opt ii) | §D |
| Fix on bot 46 | Would **not** have fired at 121B; still fires below ~115.42B / above ~130.15B | §D |
