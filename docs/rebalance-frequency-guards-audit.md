# Rebalance Frequency Guards — is the bot protected against runaway rebalancing?

> **Bottom line (one sentence):** Rebalance frequency is currently limited only
> by **the 10-minute scheduler cadence plus the post-fix natural hysteresis
> (price must move roughly a full grid-width — ≈6% for bot 46 — past the freshly
> re-centered grid edge before it can re-fire)** — there is **no cooldown, no
> per-day cap, and `last_rebalance_at` / `rebalance_count` are written but never
> read as limits; a runaway cascade in a sustained trend **IS bounded**, by the
> **Kill-Switch stop-loss halting the bot at `stop_loss_percent` from the frozen
> launch anchor (≈2–3 rebalances at a 15% stop over a 6% grid) and by monotone
> budget exhaustion** (`effectiveBudget = total_capital − capital_locked_irt`) —
> so a **dedicated frequency guard is NOT strictly needed**, with one caveat: a
> bot left with `stop_loss_percent = 0/null` loses the Kill-Switch backstop and
> is then bounded only by budget, and for that gap a cheap `last_rebalance_at`
> cooldown is the minimal safe insurance.

This report is **read-only reconnaissance**. No code was changed. Every claim is
quoted to `file:line`. It builds on the just-landed early-rebalance fix
(`docs/rebalance-trigger-audit.md`, PR #164), which is present at HEAD: the
range check now measures min/max from the **full current grid generation**
(`AdjustGridJob.php:253-263`, `OrderRegistry::getGridExtentForBot`
`OrderRegistry.php:91-127`) instead of the shrunken open-order band. That fix is
what creates the hysteresis quantified in §A4 and §D.

---

## A. What limits rebalance FREQUENCY today?

### A1. Per-day / per-window cap on rebalance count? — **NONE.**

`rebalance_count` is only ever **incremented**, never **read as a limit**:

- Incremented once per real rebalance: `AdjustGridJob.php:316`
  `$bot->increment('rebalance_count');` (guarded by `$rebalanceApplied`,
  `:305`, `:314` — an empty diff does not bump it).
- Written at init: it defaults to `0` (`migration
  2025_10_23_000001_add_missing_columns_to_bot_configs_table.php:18`).
- **Every other reference is a read for display/tests, never a gate.** A repo-wide
  search for `rebalance_count` returns: the increment (`AdjustGridJob.php:316`),
  the column definition (`BotConfig.php` fillable / migration), test assertions
  (`tests/Feature/Jobs/AdjustGridBookkeepingTest.php:134`,
  `AdjustGridRangeCheckTest.php:126,131`), and docs. **No `if (rebalance_count >
  …)` anywhere.** There is also **no daily reset** — the counter is lifetime, so
  it could not back a per-day cap without new code.

### A2. Minimum time gap / cooldown using `last_rebalance_at`? — **NONE (written, never checked).**

`last_rebalance_at` is **written** in exactly two places and **read as a guard in
zero**:

- Written on a real rebalance: `AdjustGridJob.php:315`
  `$bot->update(['last_rebalance_at' => now()]);`.
- Written at (re)initialization: `TradingEngineService.php:175`
  `'last_rebalance_at' => now()`.
- Cast to datetime and fillable: `BotConfig.php:56`, `BotConfig.php:100`.
- **No code reads it to block a too-soon rebalance.** A repo-wide search shows
  only the two writes above plus test assertions
  (`AdjustGridBookkeepingTest.php:135-142,157,169`) and docs. `AdjustGridJob`
  never compares `now()` against `last_rebalance_at` before planning. So the
  field the fix now correctly maintains is available for a cooldown but **nothing
  consumes it yet.**

Historical note: a `REBALANCE_COOLDOWN` constant once existed but was **deleted**
as dead code (referenced only by removed methods) — `PROMPT_A_SUMMARY.md:54`
("Constants removed … `REBALANCE_COOLDOWN`"). So a cooldown concept was
contemplated and removed; there is no live cooldown today.

Also unwired: `BotConfig::needsRebalance()` (`BotConfig.php:310-320`), a
center-anchored deviation test, **has no callers** in application code (only its
definition and `DEVELOPER_ONBOARDING.md`). It is not a frequency guard and is not
invoked.

### A3. Is the scheduler cadence the ONLY pacing? — **Effectively yes, plus §A4 hysteresis.**

- `AdjustGridJob` is scheduled at `interval_adjust_grid` → default **600 s = 10
  min**: `routes/console.php:40-48`, cadence from
  `config('trading.scheduler.interval_adjust_grid', 600)` (`config/trading.php:190`),
  mapped by `ScheduleCadence::methodForSeconds(600)` → `everyTenMinutes()`, falling
  back to `everyTenMinutes` if non-mappable (`routes/console.php:42`).
- `->withoutOverlapping(20)` (`routes/console.php:47`) and `->onOneServer()`
  (`:48`) — plus an in-handle global `Cache::lock('grid:adjust:global', 30)`
  (`AdjustGridJob.php:41`) and a per-bot lock (`:95-96`) — **prevent concurrent /
  overlapping runs and multi-server double-fire.** They do **not** slow the
  cadence: they only guarantee at most one run per tick. So the *upper bound* on
  rebalances is **one per bot per 10-minute tick**; they cannot batch two
  rebalances into one tick, but they also never enforce a gap longer than 10 min.

**So the only hard pacing primitive is the 10-min tick.** Whether a given tick
*does* rebalance is decided by the range check (§A4).

### A4. How far must price move to re-trigger after a fresh rebalance? — **≈ one full grid-width (~6% for bot 46). Real hysteresis now exists.**

The range check (`AdjustGridJob.php:265-292`):

```
gridRange = maxPrice − minPrice
threshold = 0.5 × gridRange
skip if:  (maxPrice − price) > −threshold  AND  (price − minPrice) > −threshold
       ↔ minPrice − threshold  <  price  <  maxPrice + threshold
```

Post-fix, `minPrice`/`maxPrice` come from `getGridExtentForBot` — the **full
current grid generation** (`AdjustGridJob.php:253-258`,
`OrderRegistry.php:91-127`), i.e. after a rebalance the **newest `rebalance`
batch's** min/max (`OrderRegistry.php:93-102`, newest-cluster isolation
`:108-124`), not the shrunken open band.

A rebalance re-plans the grid around the **current mid** (`GridPlanner::plan`,
`AdjustGridJob.php:220-226`), so the fresh grid is roughly centered on price `P`
with half-width `h`. For bot 46 the observed full grid was
`119,104,716,200 … 126,470,309,730` → width ≈ **6.0% of center**
(`docs/rebalance-trigger-audit.md` §B2), so `h ≈ 3%` and `threshold = 0.5 ×
6% = 3%`. The re-fire (lower) edge is:

```
minPrice − threshold = (P − h) − threshold ≈ (P − 3%) − 3% = P − 6%
```

**So after a rebalance re-centers, price must move ~6% (one full grid-width)
below the new center before the next downward rebalance fires** — symmetric on
the upside. This is genuine hysteresis and is a direct *consequence of the fix*:
before the fix the threshold collapsed with the open-order band and the bot could
re-fire on the very next tick (that was the bug). It **cannot** re-fire on the
next 10-min tick unless the trend is fast enough to move a full grid-width inside
10 minutes (§D).

---

## B. What stops a runaway cascade in a trending market?

### B1. Kill-Switch — **bounds the damage IF armed; the anchor is frozen so it trips monotonically in a trend.**

`KillSwitchService::checkAndTrigger` runs **first** on every bot every tick,
*before* any planning/rebalance: `AdjustGridJob.php:115-123` — if it trips the
bot is skipped this tick (`continue`, `:122`) and, having set `is_active =
false`, is **excluded from all future ticks** (`BotConfig::where('is_active',
true)`, `AdjustGridJob.php:54`). That is the halt path.

**Stop-loss measures distance from the FROZEN launch anchor.**
`evaluateStopLoss` reads `$anchor = $bot->grid_center_price`
(`KillSwitchService.php:86`) and computes
`abs((current − anchor)/anchor × 100)` (`:115-117`), tripping when it exceeds
`stop_loss_percent` (`:119`). Crucially, `grid_center_price` is written **only at
(re)initialization** (`TradingEngineService.php:171`) and is **read-only on the
rebalance path** — a repo-wide search for `grid_center_price` shows writes only at
`TradingEngineService.php:171` and reads at `KillSwitchService.php:86` and
`AdjustGridJob.php:370` (the reconstruct fallback, explicitly "READ-ONLY w.r.t.
grid_center_price", `AdjustGridJob.php:363`). **Nothing resets the anchor on
rebalance.** Therefore, as a sustained downtrend carries price away from the
launch mid, the Kill-Switch distance **grows monotonically** and eventually
crosses `stop_loss_percent` → `trip()` sets `is_active = false`,
`stop_reason = 'kill_switch:stop_loss'`, `stopped_at = now()`, and saves
(`KillSwitchService.php:192-204`). One-way, no auto-recovery (`:22-24`).

**Concrete bound:** each rebalance re-centers the *grid* ~6% lower before the next
fires (§A4), but the *anchor* stays at launch, so cumulative distance from anchor
≈ N × 6%. With the create-form / recommended stop of ~15%
(`config/trading.php:166` default 15; create default max-drawdown 10,
`CreateBotConfig.php:120`), the switch trips after roughly **15% / 6% ≈ 2–3
rebalances** into a one-directional move, then halts. So the downside chase is
bounded at ≈`stop_loss_percent` from launch.

**Caveat (the real gap):** the switch is **only armed if the bot row has
`stop_loss_percent > 0` or `max_drawdown_percent > 0`**
(`KillSwitchService.php:53-57` — returns `noTrigger` when neither is set). UI-created
bots always get both (`CreateBotConfig.php:119-120`), but a bot seeded/imported
with `stop_loss_percent = 0/null` has **no price backstop**, leaving only budget
(§B2) to bound the cascade. And `max_drawdown_percent` alone is a weak backstop in
a downtrend: it sums only **realized** losses on **completed** trades
(`KillSwitchService.php:148-153`) — a bot buying a falling market accumulates
*unrealized* losses (filled buys with open, underwater `cycle_exit` sells that
never close), which do not register as drawdown until they close.

### B2. Budget / capital exhaustion — **a monotone, self-limiting hard bound; graceful skip, not a crash.**

`AdjustGridJob.php:183` computes
`effectiveBudget = Money::sub(total_capital, capital_locked_irt)`. When
`effectiveBudget ≤ 0` the bot's rebalance is **skipped for that tick** with a
logged `REBALANCE_SKIP_NO_AVAILABLE_BUDGET` (`:189-197`) — explicitly **not** a
Kill-Switch: the bot stays active and its `cycle_exit` sells keep working
(`:186-188`).

In a downtrend this is self-limiting and monotone:

- `capital_locked_irt` is the summed IRT behind **filled buys waiting to sell**
  (open `cycle_exit` sells), maintained by `GridOrderObserver`
  (`AdjustGridJob.php:143-148`). As price falls, buys fill → locked capital
  **rises**, so `effectiveBudget` **falls** toward 0.
- **A rebalance does NOT free that locked capital.** The diff **protects
  `cycle_exit` (and `manual`) orders from cancellation**
  (`GridOrderSync.php:107-128`), and the locked IRT sits behind *filled* buys, not
  the unfilled grid legs that a rebalance cancels. So each rebalance can cancel &
  re-place the *open* grid, but cannot reclaim capital already committed to
  underwater longs.
- Consequently the bot can only fund a **finite** number of buy grids before
  `effectiveBudget ≤ 0`, after which it deploys no new grid. This bounds the
  chase even with the Kill-Switch disarmed — gracefully (a warning log), never a
  silent failure or crash.

### B3. Trend-vs-chop detection — **NONE. The bot is blind to direction.**

There is no code anywhere that distinguishes a one-directional trend from
oscillation before rebalancing. The only rebalance decision input is the
symmetric distance-outside-grid test (`AdjustGridJob.php:273`); it fires
identically whether price left the grid once (chop) or is trending through it.
The deviation-based `needsRebalance()` (`BotConfig.php:310`) is unused (§A2). So
the bot cannot "pause because this looks like a trend."

---

## C. What does a rebalance actually COST each time?

### C1. LIVE: a diff-based teardown+rebuild, one exchange call per changed order.

A rebalance is **not** a batched operation and **not** always a full teardown —
it is a **diff** (`GridOrderSync::diff`, `AdjustGridJob.php:298`):

- Orders whose price is within `toleranceTicks × tick` and qty within 3% are
  **kept** (`GridOrderSync.php:75-86`); everything else open-and-unmatched is
  **cancelled** (`:104-131`), except `cycle_exit`/`manual`, which are protected
  (`:107-128`); every missing plan level (above min notional) is **placed**
  (`:87-101`).
- When price has moved a full grid-width (the condition that triggered the
  rebalance in the first place, §A4), essentially **all** surviving open grid legs
  fall out of the new plan → cancelled, and a full new set (~`grid_levels`
  orders) is placed. So a triggered rebalance is, in practice, close to a full
  teardown+rebuild of the open grid.
- Execution is **one exchange call per order, serialized**: each cancel is a
  `cancelOrder` + 250 ms sleep (`GridOrderExecutor.php:100,115`); each place is a
  `createOrder` + 300 ms sleep (`:247,269`). No bulk endpoint.

**On fees:** Nobitex charges on **execution (fills)**, not on placing or
cancelling resting limit orders. So the direct cost of the churn is **not** a fee
per cancel/replace; it is (a) the fills the re-placed lower grid generates as it
**chases price down** (buys filling into a falling market, realizing worse
average entries), (b) lost maker position on the cancelled orders, and (c) the
spread/slippage of re-centering. The "fee bleed" concern is real but is mediated
by *fills*, which the ~6% hysteresis (§A4) throttles.

### C2. SIMULATION: zero real cost — churn is free on the test bot.

When `$bot->simulation` is true (`AdjustGridJob.php:125`, passed to
`applyForBot(..., simulation: $simulate)`, `:308`), the executor makes **no real
API calls**: cancels just flip the local row to `cancelled` + log `EXEC_SIM_CANCEL`
(`GridOrderExecutor.php:75-92`), and places just insert a `SIM-*` GridOrder row +
log `EXEC_SIM_PLACE` (`:158-200`). So a simulated rebalance costs **no real fees
and no exchange calls** — only DB writes and log lines. **Do not over-worry about
churn on a simulation bot; the frequency concern is a LIVE-only risk.**

---

## D. Quantify the risk with bot 46 / realistic numbers

Using bot 46's real, observed grid (`docs/rebalance-trigger-audit.md` §B):
full grid `119,104,716,200 … 126,470,309,730`, center ~122.7B, **width ≈ 6.0%**
(4 `initial_grid` legs → ~2 per side; the exact `grid_levels`/`grid_spacing`
don't matter — the empirical width is 6%). Threshold = 0.5 × 6% = **3%**, and the
re-fire edge sits a **full grid-width ≈ 6%** past the re-centered mid (§A4).

At the 10-min cadence, "how often would rebalances fire" is set by how long a
steady trend takes to move ~6%:

| Sustained trend speed | Time to move ~6% | Rebalance cadence |
|---|---|---|
| **36%/hr** (flash crash) | 10 min | **every tick** (worst case) |
| 12%/hr | 30 min | ~every 3 ticks |
| **6%/hr** | 1 hour | **~1 rebalance/hour** (every 6 ticks) |
| 3%/hr | 2 hours | ~every 12 ticks |
| 1%/hr | 6 hours | ~every 36 ticks |
| a few % **per day** (normal) | ≫ hours | **essentially never re-fires from trend** |

So: to rebalance on **every** 10-min tick you need a **~36%/hour** sustained
collapse; even a fierce **6%/hour** trend produces only **~1 rebalance/hour**.
And the Kill-Switch (§B1, ~15% stop) caps the whole episode at **≈2–3
rebalances** before halting; budget exhaustion (§B2) caps it independently.

**Verdict on "normal vs violent":** the current setup is **"fine for normal
markets; only meaningfully churny in a violent, sustained one-directional trend
— and even then paced to roughly one rebalance per full grid-width (~6%) of
adverse move and hard-halted by the Kill-Switch after ~2–3 of them (when armed)."**
It **cannot** churn in ordinary chop, because re-firing now requires leaving a
full re-centered grid (the early-rebalance fix removed the hair-trigger). The one
scenario that is *not* tightly bounded is a violent trend on a bot whose
`stop_loss_percent` is unset — bounded then only by budget.

---

## E. Recommendations (specified, NOT implemented)

### Is a dedicated frequency guard even NEEDED?

**Largely no, given B/C/D** — for a properly-configured (armed) bot the risk is
already bounded on three independent axes: the ~6% post-fix hysteresis (§A4)
paces re-fires, the Kill-Switch stop-loss hard-halts at ~`stop_loss_percent` from
the frozen anchor (§B1), and budget exhaustion is a monotone hard bound (§B2).
The residual risk is narrow: **a bot with `stop_loss_percent = 0/null` in a
violent sustained trend**, where only budget bounds the chase. The cheapest way to
close that gap is operational (ensure every live bot has a non-zero
`stop_loss_percent`) plus, optionally, a small cooldown as defense-in-depth.

Options, in order of preference:

#### E1. (Recommended) Cooldown — min minutes between rebalances via `last_rebalance_at`.

- **Where it hooks in:** `AdjustGridJob`, immediately **after** the Kill-Switch
  gate and **before** planning — i.e. between `AdjustGridJob.php:123` and the
  budget block at `:137` (or just before `$planner->plan(...)` at `:220`). Read
  `$bot->last_rebalance_at`, and if `now()->diffInMinutes(last_rebalance_at) <
  config('trading.rebalance.min_gap_minutes')`, log + `continue`.
- **Pros:** near-free — `last_rebalance_at` is **already correctly maintained**
  (`AdjustGridJob.php:315`, and only on a *real* rebalance, `:314`); no
  market-data, no new column, no daily-reset bookkeeping; directly caps
  worst-case flash-crash churn (the "every tick at 36%/hr" row in §D) and
  protects bots that lack a stop-loss. Deterministic and trivially testable.
- **Cons:** a *genuine* fast breakout must wait out the cooldown before the grid
  re-centers (acceptable — that is exactly the churn we want to damp; pick the gap
  ≥ a small multiple of the cadence, e.g. 20–30 min).
- **Minimal, safest — this is the recommendation** if any guard is added.

#### E2. Per-day rebalance cap (`rebalance_count`-based, reset daily).

- **Where:** same gate location in `AdjustGridJob`; compare a *daily* counter to a
  cap.
- **Pros:** an absolute ceiling on daily churn regardless of trend speed.
- **Cons:** heavier — `rebalance_count` is **lifetime with no reset**
  (`AdjustGridJob.php:316`, migration default 0), so this needs a new
  daily-bucketed counter (new column or a reset job) and bookkeeping. A blunt cap
  can also *starve* a legitimately volatile market late in the day. More moving
  parts than E1 for the same protection. **Not recommended over E1.**

#### E3. Trend/volatility check before rebalancing.

- **Where:** in the range-check block (`AdjustGridJob.php:265-292`) or a new
  predicate; e.g. skip/dampen when recent price action is one-directional beyond a
  band.
- **Pros:** the most "correct" — directly targets the trend-vs-chop blindness
  (§B3).
- **Cons:** needs market-data/indicator input and tuning, adds real complexity and
  new failure modes, and overlaps what the Kill-Switch already does more simply
  (halt on sustained distance from anchor). **Overkill now.**

#### E4. Rely on Kill-Switch + budget exhaustion as sufficient.

- **Pros:** zero new code; B/C/D show these already bound the risk for armed bots.
- **Cons:** leaves the `stop_loss_percent = 0/null` bot exposed to budget-only
  bounding; no cap on flash-crash per-tick churn within the pre-halt window.

### Recommendation

**Primary: E4 + an operational guarantee that every live bot has a non-zero
`stop_loss_percent`** (which arms the Kill-Switch, §B1) — this alone bounds the
cascade acceptably and requires no code. **If defense-in-depth is wanted, add
E1**, a small (~20–30 min) cooldown read from the already-maintained
`last_rebalance_at`, hooked in just after the Kill-Switch gate
(`AdjustGridJob.php:123`). It is the minimal, safest, lowest-risk addition and is
the only option that also protects an un-armed bot from flash-crash churn. Avoid
E2/E3 unless a hard daily ceiling or true trend-awareness becomes a product
requirement.

---

## Summary table

| Question | Finding | Evidence |
|---|---|---|
| Per-day / count cap? | **No.** `rebalance_count` incremented, never read as a limit, no daily reset | `AdjustGridJob.php:316`; no `if(rebalance_count>…)` in repo |
| Cooldown via `last_rebalance_at`? | **No.** Written, never read as a guard | writes `AdjustGridJob.php:315`, `TradingEngineService.php:175`; zero reads |
| Only pacing = cadence? | **Cadence (10 min) + post-fix ~6% hysteresis.** Locks are anti-overlap, not gaps | `routes/console.php:40-48`; `AdjustGridJob.php:41,95` |
| Re-trigger distance after rebalance | **~full grid-width (~6% for bot 46)** below/above new center | `AdjustGridJob.php:253-292`, `OrderRegistry.php:91-127`; §A4 |
| Kill-Switch bounds cascade? | **Yes if armed** — frozen `grid_center_price` anchor, distance grows monotonically, trips at `stop_loss_percent` → `is_active=false` | `KillSwitchService.php:86,115-119,192-204`; `AdjustGridJob.php:115-123,54` |
| Anchor reset defeats it? | **No.** `grid_center_price` written only at init, read-only on rebalance | writes `TradingEngineService.php:171`; reads `KillSwitchService.php:86`, `AdjustGridJob.php:370` |
| Budget exhaustion bounds it? | **Yes** — `total_capital − capital_locked_irt`; locked rises monotonically in a downtrend, graceful skip | `AdjustGridJob.php:183,189-197`; `GridOrderSync.php:107-128` |
| Trend vs chop detection? | **None** — bot is blind; `needsRebalance()` unused | `BotConfig.php:310`; §B3 |
| Cost per rebalance (live) | Diff teardown+rebuild, 1 exchange call/order; fees hit on **fills**, not cancels | `GridOrderExecutor.php:100,115,247,269`; `GridOrderSync.php:75-131` |
| Cost per rebalance (sim) | **Zero** real fees/API — DB writes + logs only | `GridOrderExecutor.php:75-92,158-200` |
| Dedicated frequency guard needed? | **Not strictly** for armed bots; cheap `last_rebalance_at` cooldown recommended as insurance (esp. un-armed bots) | §E |
