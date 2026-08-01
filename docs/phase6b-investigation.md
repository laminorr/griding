# Cleanup Phase 6b — Investigation Report (no fix applied)

**Scope:** Investigation only. No production code, migration, config, or test was
changed in this session. This document is the sole deliverable.

**Repo:** `laminorr/griding` · Laravel + Filament grid bot for Nobitex (BTCIRT),
PHP 8.3, ext-bcmath.

**Note on orientation:** the task referenced `docs/bot-inventory.md`, but that file
does not exist on this branch (only `docs/phase13-investigation.md` is present in
`docs/`). Orientation was therefore done directly against the source. All file:line
references below were read from the working tree at HEAD `4d100e7`.

---

## Top-line summary

| Issue | Reachable in production today? | Verdict |
|-------|-------------------------------|---------|
| **4 — cross-bot capital race** | Only via two *concurrent, manual* admin start actions on two different bots. No automated/queued init path exists; single-server deployment; `Start All` runs sequentially. | **(B)** Unreachable in normal operation today, but worth a cheap guard for safety. Recommended fix = a `Cache::lock` around the check+place window. **Do NOT** use `capital_locked_irt` as the reserved-capital signal — it is 0 at init and would create false safety / over-ordering risk. |
| **5 — legacy `GridOrderExecutor::apply()`** | No. Its only caller is a dead `else` branch guarded by `method_exists($exec, 'applyForBot')`, which is always true. Zero test callers. | **(B/C)** Dead code, harmless today. Recommend deletion (plus collapsing the dead guard) as a zero-risk cleanup in a later phase. |

---

## Issue 4 — cross-bot capital race

### 4.1 Exact current logic

**Entry point.** `TradingEngineService::initializeGrid()`
(`app/Services/TradingEngineService.php:60`) is the only live grid-init path. At
step 5b it calls the balance check:

```
app/Services/TradingEngineService.php:140
    $balanceCheck = $this->verifySufficientQuoteBalance(
        $botConfig, $gridResult['grid_levels'], $orderSizeResult['crypto_amount']
    );
```

**The check itself** — `verifySufficientQuoteBalance()`
(`app/Services/TradingEngineService.php:400-464`):

1. Short-circuits to success for `simulation`, for `mode === 'sell'`, and when there
   are no buy levels (`:402-417`).
2. Reads the balance (`:423-427`):
   ```
   $balances  = $this->nobitexService->getBalances();
   $available = Money::normalize($balances[$quoteCurrency]['available'] ?? 0);
   ```
   This is the **total account** available balance for the quote currency
   (`rls`/IRT) — there is no per-bot partitioning.
3. Sums this bot's required notional across its buy levels (`:433-439`):
   `requiredNotional = Σ (level.price × orderSize)`.
4. Adds a fee buffer (`:441-445`): `required = requiredNotional × (1 + fee_bps/10000)`.
5. Fails only if `available < required` (`:447-458`).

**What it sums:** only *this* bot's planned buy-side notional + fee.
**What balance it reads:** the whole account's `available` quote balance, once, with
no reservation for any other bot.

The method's own docblock (`:388-398`) already documents the gap verbatim: *"two
bots racing to start at the same time can both pass this check against the same
pre-spend balance."*

### 4.2 Is there any existing locking / mitigation?

**No — not on this path.** Searched all `Cache::lock` / DB-lock / `capital_locked_irt`
reads (`grep -rn "Cache::lock\|->lock(\|capital_locked_irt" app/`):

- `initializeGrid()` (`:60-235`) takes **no** lock of any kind. The check at `:140`
  and the order placement at `:153` (`placeGridOrders` → `GridOrderExecutor::applyForBot`)
  are not serialized against any other bot's init.
- Locks that *do* exist are on **other** paths and do not help here:
  - `AdjustGridJob` — global lock `grid:adjust:global` (`app/Jobs/AdjustGridJob.php:41`)
    + per-bot lock (`:96`). This is the rebalance path; it never calls
    `verifySufficientQuoteBalance`.
  - `CheckTradesJob` per-order `pair-order:{id}` lock (`app/Jobs/CheckTradesJob.php:688`).
  - `SubmissionReconciler` per-row `reconcile-order:{id}` lock
    (`app/Services/SubmissionReconciler.php:124`).
- `capital_locked_irt` is **not read anywhere in the init path.** Its only consumer
  is `AdjustGridJob` (`:159, :180`), for rebalance budget sizing — a different concern.

So there is **zero** cross-bot mitigation for the init-time balance check today.

### 4.3 Can `capital_locked_irt` serve as the reserved-capital signal? — No.

This is the most important finding for Issue 4. **`capital_locked_irt` is the wrong
signal, and using it would create *false safety*, not protection.**

`GridOrderObserver::recomputeInventoryForBot()`
(`app/Observers/GridOrderObserver.php:97-144`) computes `capital_locked_irt` as the
summed notional of **buy-side open *cycles* only** — i.e. `cycle_exit` **sell** orders
at status `placed`, valued from their paired filled buy (`:107-130`):

```
->where('role', 'cycle_exit')->where('status','placed')->where('type','sell')
```

Crucially, **the initial grid buy orders are not `cycle_exit`.** They are stamped
`role = 'initial_grid'` by `GridOrderExecutor::applyForBot()`
(`app/Services/TradingEngineService.php:734` passes `role: 'initial_grid'`;
executor writes it at `GridOrderExecutor.php:178, 217`). A freshly-initialized bot has
**no** `cycle_exit` orders at all, so its `capital_locked_irt` is **0** — confirmed in
prose by `AdjustGridJob`:

```
app/Jobs/AdjustGridJob.php:154-157
    // Initial placement (TradingEngineService::initializeGrid) needs no change:
    // at init there are no open cycles, so capital_locked_irt is 0 and
    // effectiveBudget == total_capital.
```

Consequences of using `Σ other bots' capital_locked_irt` as the reserved amount:

- **It is exactly 0 in the concurrent-init scenario the race describes.** Two bots
  both starting at once each have `capital_locked_irt = 0`, so subtracting the sum
  subtracts nothing — the race is unaffected. This would ship a guard that looks like
  protection but changes nothing in the one case that matters. **Higher risk than
  doing nothing**, because it invites the assumption "the race is handled."
- **Race window on the observer.** Even for bots that *have* built cycles, the
  observer recomputes on `saved`/`deleted` of each `GridOrder`
  (`GridOrderObserver.php:54-62`) and during placement fires 4-10 times in rapid
  succession (`:36-40`). The value read by a concurrent `verifySufficientQuoteBalance`
  can lag the true committed capital by a full placement burst.
- To make a reserved-capital subtraction *correct* you would need a new signal that
  captures **committed-but-unfilled buy notional** (sum of `placed` buy orders including
  `initial_grid`, per active bot) — which `capital_locked_irt` deliberately does not
  track. That is a new column/aggregation, not a reuse.

**Verdict: do not repurpose `capital_locked_irt` for this.**

### 4.4 How many bots run concurrently? Is it constrained to 1?

**No hard constraint to a single bot.** Evidence:

- Multi-bot is a first-class assumption: `BotConfig::where('is_active', true)->get()`
  returns a collection and is looped in `CheckTradesJob.php:58` and
  `AdjustGridJob.php:54`. There is **no** unique index or guard limiting active bots to
  one per symbol (searched migrations + models; `bot_configs` has no such constraint).
- **But there is no concurrent *init* path.** `initializeGrid()` is triggered only by
  three **synchronous Filament UI actions**:
  - `CreateBotConfig.php:438` (create-and-start one bot),
  - `ListBotConfigs.php:96` (`startAllBots()` — a **sequential** `foreach` over
    inactive bots inside a single HTTP request, `:93-113`),
  - `BotConfigResource.php:461` (start one bot).
  None of these is scheduled or queued. `routes/console.php` schedules
  `CheckTradesJob`, `AdjustGridJob`, `ReconcileSubmissionsJob`, `ReadMarketStatsJob` —
  **not** `initializeGrid`.
- Deployment is single-server (`routes/console.php:32`:
  *"onOneServer() removed - not needed for single server setup"*).

**Reachability conclusion.** The cross-bot race requires **two truly-concurrent
`initializeGrid` invocations for two different bots** — which can only happen if an
admin fires two start actions at the same instant (two browser tabs / two admin
sessions / a double-submit that reaches two bots). The common `Start All` path is
*sequential* and additionally self-correcting: each iteration re-reads live
`available`, which Nobitex reduces as the previous bot's limit buys are placed
(open orders lock quote balance). So in normal single-admin operation the race is
**not reachable**; it is a latent hazard only under simultaneous manual starts.

### 4.5 Fix options (ranked by risk/complexity)

**Option (b) — `Cache::lock` around the check+place window · LOWEST RISK · RECOMMENDED**
- *What it changes:* wrap `verifySufficientQuoteBalance()` **and** `placeGridOrders()`
  (`TradingEngineService.php:140-158`) in a `Cache::lock` keyed on the account/quote
  currency (e.g. `capital:init:{quoteCurrency}`), so concurrent inits serialize and the
  second sees the first's placed orders reflected in the next `getBalances()` read.
- *What could go wrong:* the lock is held across live exchange calls, so it must have a
  sensible TTL and block-timeout to avoid one slow/crashed init stalling another (the
  TTL prevents a permanent deadlock; a crashed holder auto-releases). Choosing too short
  a TTL could release mid-placement. Requires `CACHE_STORE=database|redis` (already the
  case per `AdjustGridJob.php:47`).
- *How to test:* unit-test that two `initializeGrid` calls cannot interleave (assert the
  second blocks until the first releases, e.g. via a spy on `Cache::lock`); integration
  test with a fake NobitexService whose `getBalances()` decrements as orders are placed,
  asserting the second bot is correctly rejected when the first exhausts balance.
- *Matches existing patterns* (`AdjustGridJob`, `CheckTradesJob`, `SubmissionReconciler`
  all use `Cache::lock`), so it is idiomatic and reviewable.

**Option (c) — document as acceptable · LOW EFFORT · conditionally valid**
- *What it changes:* nothing in code; add a comment/ops note stating the race is
  accepted because init is manual + sequential on a single server.
- *What could go wrong:* the "single admin, never two concurrent starts" assumption is
  operational, not enforced — a second admin, a mobile + desktop session, or a
  double-click could still trigger it. Weaker than (b), and the existing docblock
  (`:388-398`) already half-documents it.
- *How to test:* n/a.

**Option (a) — reserved-capital subtraction · HIGHEST RISK · NOT RECOMMENDED as scoped**
- *What it changes:* subtract other active bots' reserved capital from `available`
  before comparing.
- *What could go wrong:* as shown in §4.3, the obvious signal (`capital_locked_irt`) is
  **0 at init** and would not close the race while *appearing* to — the worst outcome
  (false safety → over-ordering when someone later trusts it). A correct version needs a
  **new** committed-buy-notional aggregate per bot, plus care around the observer's
  async recompute window. High complexity, high blast radius, easy to get wrong.
- *How to test:* would need coverage proving the new signal counts `initial_grid`
  `placed` buys — precisely the coverage that reveals `capital_locked_irt` is unfit.

### 4.6 Single recommendation for Issue 4

**Classify as (B): not reachable in normal operation today, worth a cheap guard.**
When it is scheduled for a fix, use **Option (b) — a `Cache::lock` around the
check+place window.** It is the smallest change, closes the actual window, and matches
existing concurrency patterns. **Explicitly reject Option (a) as scoped** (the
`capital_locked_irt` subtraction) — it is the wrong signal and would introduce
over-ordering risk under the guise of a fix. Do not implement now.

---

## Issue 5 — legacy `GridOrderExecutor::apply()`

### 5.1 Current behaviour

`GridOrderExecutor::apply(array $diff, bool $simulation = true)`
(`app/Services/GridOrderExecutor.php:302-429`) is the legacy, **unscoped** executor:

- Takes no `$botId` — cannot associate work with a bot.
- **Creates no `GridOrder` rows** (contrast `applyForBot`, which persists intent rows
  at `:170` and `:210`).
- **No dedup guard** — no `client_order_id` check, so a timeout-retry could place a
  duplicate exchange order (contrast `applyForBot` `:148-161, :189-203`).
- **No `role` stamping.**

Its own docblock (`:297-301`) says: *"Legacy entry point kept for backward
compatibility. … Production code should call `applyForBot()` instead."*

### 5.2 Every caller (exhaustive)

Commands run:
```
grep -rn -- "->apply(" app/ tests/ routes/ database/
grep -rn -- "::apply(" app/ tests/ routes/ database/
grep -rniw "apply" app/ tests/ routes/ database/ config/   # dynamic/string dispatch
```

Result — **exactly one** reference to this method anywhere:

```
app/Jobs/AdjustGridJob.php:279
    $exec->apply($diff, simulation: $simulate);
```

…and it sits in a **dead branch**:

```
app/Jobs/AdjustGridJob.php:276-284
    if (method_exists($exec, 'applyForBot')) {
        $exec->applyForBot($bot->id, $diff, simulation: $simulate, role: 'rebalance');
    } else {
        $exec->apply($diff, simulation: $simulate);          // <-- unreachable
        Log::channel('trading')->warning('USING_UNSCOPED_APPLY', [...]);
    }
```

`$exec` is type-hinted `GridOrderExecutor` (`AdjustGridJob.php:34`), and
`GridOrderExecutor` **defines `applyForBot`** (`GridOrderExecutor.php:49`). Therefore
`method_exists($exec, 'applyForBot')` is **always true** and the `else` branch — the
only caller of `apply()` — is **never executed in production**.

No other production path, console command, route, migration/seeder, Filament page, or
service-container binding references `apply()` (the other `grep` hits for the word
"apply" are unrelated comments — "Apply options/filter/sorting" — and the `GridRunOnce`
command description string).

### 5.3 Is it called by any production path? — No.

Dead defensive fallback only. Confirmed unreachable given the concrete class always has
`applyForBot`.

### 5.4 Test coverage

- **No test calls `apply()`.** `grep -rn "apply(" tests/ | grep -v applyForBot` → empty.
- The executor's behaviour is locked in by `GridOrderExecutorTest`
  (`tests/Feature/Trading/GridOrderExecutorTest.php`), which exercises **only**
  `applyForBot` (`:85, :117, :137, :172`; header says *"behaviour lock-in for
  GridOrderExecutor::applyForBot()"* `:19-20`).
- The two mocks/stubs of the class
  (`tests/Unit/Services/TradingEngineServiceHelpersTest.php:87` via `Mockery::mock`,
  `tests/Feature/Jobs/AdjustGridAllowedSymbolsTest.php:57` via `createMock`) both produce
  subclass doubles that **still have `applyForBot`**, so even under test
  `method_exists(...)` stays true and the `else` branch is never taken.

**Removing `apply()` would lose no test coverage.**

### 5.5 Fix options

**Option (a) — delete `apply()` and collapse the dead guard · RECOMMENDED**
- *What it changes:* remove `GridOrderExecutor::apply()` (`:302-429`) and simplify
  `AdjustGridJob.php:276-284` to call `applyForBot` directly (drop the `method_exists`
  branch and the `USING_UNSCOPED_APPLY` warning, since the fallback can never fire).
- *What could go wrong:* essentially nothing — zero production callers, zero test
  coverage lost. The only caveat is that it is a **two-part** change (delete the method
  *and* remove the now-pointless guard); deleting the method alone would leave
  `AdjustGridJob:279` referencing a missing method in an unreachable branch (still never
  executed, but a latent fatal if the guard were ever forced false).
- *How to test:* the existing suite (329 tests) must stay green; `AdjustGridAllowedSymbolsTest`
  in particular continues to pass since it drives the `applyForBot` path.

**Option (b) — deprecate (`@deprecated`, keep)**
- *What it changes:* annotate `apply()` `@deprecated`, optionally `throw` in
  non-production. Keeps the method.
- *What could go wrong:* leaves ~130 lines of unscoped, dedup-free code in the tree that
  could be miscalled by future work — the exact hazard the docblock already warns about.
  Lower payoff than deletion for a method with no callers.

**Option (c) — keep with a guard**
- Redundant: the current `method_exists` check already *is* the guard, and it makes the
  branch dead. Keeping it preserves dead code for no benefit.

### 5.6 Single recommendation for Issue 5

**Recommend Option (a): delete `apply()` and collapse the always-true `method_exists`
guard in `AdjustGridJob`.** It is dead, unscoped, and dedup-free; removing it eliminates
a latent foot-gun with zero risk and no coverage loss. Because it is not reachable
today, this is a **hygiene cleanup (B/C)**, not an urgent correctness fix — safe to
schedule for the next cleanup phase. Do not implement now.

---

## Evidence index (file:line)

- Balance check: `app/Services/TradingEngineService.php:388-464` (logic), `:140` (call site), `:60-235` (init, no lock).
- Balance read source: `:423-427` (`getBalances()['...']['available']`, account-total).
- `capital_locked_irt` computation: `app/Observers/GridOrderObserver.php:97-144` (cycle_exit sells only).
- "0 at init" confirmation: `app/Jobs/AdjustGridJob.php:154-157`.
- Init triggers (all synchronous UI): `CreateBotConfig.php:438`, `ListBotConfigs.php:96` (sequential loop `:93-113`), `BotConfigResource.php:461`.
- Scheduler (no init job; single server): `routes/console.php:16-71`, `:32`.
- Existing locks (other paths): `AdjustGridJob.php:41, 96`; `CheckTradesJob.php:688`; `SubmissionReconciler.php:124`.
- `apply()` legacy method: `app/Services/GridOrderExecutor.php:302-429`, docblock `:297-301`.
- `apply()` sole caller (dead branch): `app/Jobs/AdjustGridJob.php:276-284`.
- `applyForBot` definition (makes guard always true): `GridOrderExecutor.php:49`; injected type `AdjustGridJob.php:34`.
- `role` stamping (`initial_grid` not `cycle_exit`): `TradingEngineService.php:734`; `GridOrderExecutor.php:178, 217`.
- Tests exercise only `applyForBot`: `tests/Feature/Trading/GridOrderExecutorTest.php:19-20, 85, 117, 137, 172`.
