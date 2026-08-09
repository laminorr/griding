# Profit Accounting Audit — How the real engine computes realized profit per cycle

**Type:** Read-only reconnaissance. No application code was modified, created, or
deleted. Every claim below is backed by source that was read, quoted with
`file:line`. Where the code was ambiguous it is traced to resolution or recorded
under "Open questions" — never filled with an assumption.

**Branch:** `claude/profit-cycle-accounting-audit-8fpijk` (auto-suffixed harness
branch, fresh off `main`: `git log --oneline -1` = `cac2803` which is exactly
`origin/main` HEAD after `git fetch origin main`; `git log origin/main..HEAD` is
empty — no extra commits).

**Runtime caveat (affects §7 only):** this container has **no `vendor/`, no
`.env`, no configured database, and `ext-bcmath` is ABSENT** (`php -m` has no
`bcmath`; `php artisan` fails on the missing autoloader). I therefore **cannot
run Tinker or issue any SELECT against bot 46** here, and cannot execute the
`Money`/bcmath math locally. §7 reconstructs the arithmetic by hand from the
stored-column semantics so the host (which has the DB + bcmath) can verify
against the real row.

---

## 1. The real runtime path of a completed cycle (files + lines, in order)

A "cycle" = one buy leg and its paired opposite-side sell leg, both `filled`.
The engine detects and books it as follows.

1. **Scheduler → job entry.** `App\Jobs\CheckTradesJob::handle()`
   (`app/Jobs/CheckTradesJob.php:55`) iterates active bots and calls
   `processBot($bot)` (`:93`).

2. **Poll live orders for fills.** `processBot()` loads orders in
   `['placed','partially_filled']` (`:107-109`) and dispatches:
   - **Simulation bots (bot 46 is `simulation=true`):**
     `checkSimulatedOrders($orders, $bot)` (`:196`). A limit-fill is decided by
     exact BCMath comparison against the last market price (`:213-224`):
     `buy` fills when `market <= order price`, `sell` when `market >= order
     price`. On a fill it does, inside a DB transaction (`:228-239`):
     ```php
     $order->update(['status' => 'filled', 'filled_at' => now()]);      // :230
     app(BotActivityLogger::class)->logOrderFilled($bot->id, $order);    // :235
     $this->createCompletedTradeIfPaired($order, $bot);                  // :237
     ```
   - **Live bots:** `checkOrdersStatus()` → `processOrderStatus()` (`:332`) →
     `handleFilledOrder()` (`:401`), which at `:454` also calls
     `createCompletedTradeIfPaired($order, $bot)`.

3. **Spawn the continuation leg (opens the cycle).** Separately, `processBot()`
   selects `status='filled'` orders with `paired_order_id IS NULL` (`:125-128`)
   and calls `createPairOrder($filledOrder, $bot)` (`:133`) →
   `createPairOrderLocked()` (`:705`). This creates the opposite-side order:
   - continuation price (`:714-723`):
     ```php
     $spacingStr = Money::div(Money::normalize($bot->grid_spacing), '100');  // e.g. "0.015"
     $rawPrice   = $newType === 'sell'
         ? Money::mul($filledOrder->price, Money::add('1', $spacingStr))     // sell = buy × (1+s)
         : Money::mul($filledOrder->price, Money::sub('1', $spacingStr));    // buy  = sell × (1−s)
     $newPrice = (int) round((float) $rawPrice);                            // :723
     ```
   - continuation quantity = **the same quantity that just filled** (`:734`):
     `$pairAmount = $filledOrder->filled_amount ?? $filledOrder->amount;`
   - new row is created with `role => 'cycle_exit'`, `paired_order_id =>
     $filledOrder->id` (`:793-802`), and the filled order is back-linked
     `paired_order_id => $newOrder->id` (`:813`). **This is the crucial fact for
     the profit math: the sell leg carries the exact same quantity as the buy
     leg.**

4. **Book the completed cycle when BOTH legs are filled.**
   `createCompletedTradeIfPaired($order, $bot)` (`:596`):
   - returns early if `paired_order_id === null` (no partner yet) (`:603-606`);
   - loads the partner via `paired_order_id` (`:609-616`);
   - **only proceeds when `partner->status === 'filled'`** (`:620-623`) — this is
     the "cycle is now complete" decision;
   - assigns buy/sell roles regardless of fill order (`:632-634`);
   - double-booking guard: skips if a `completed_trades` row already exists for
     this exact `(buy_order_id, sell_order_id)` pair (`:638-645`);
   - then calls `recordCompletedTrade($buyOrder, $sellOrder, $bot)` (`:652`).

5. **Persist the profit row.** `recordCompletedTrade()` (`:941`) computes a
   **log-only** copy of the numbers (`:953-957`, native floats, for a log line
   only) and then persists the real record via (`:976`):
   ```php
   $trade = CompletedTrade::createFromOrders($buyOrder, $sellOrder);
   ```
   `App\Models\CompletedTrade::createFromOrders()`
   (`app/Models/CompletedTrade.php:332`) is where the authoritative numbers are
   computed (all bcmath) and written with `self::create([...])` (`:413-436`).

**Where realized profit is written to the DB:** table **`completed_trades`**,
via `CompletedTrade::create()` at `app/Models/CompletedTrade.php:413`. The
authoritative realized (net) profit is column **`profit`** (set from
`$netProfit`, `:420`); `net_profit` (`:423`) holds the same value at higher
scale, `gross_profit` (`:422`) the pre-fee figure, `fee` (`:421`) the total fee.

**There is no second profit sink.** `bot_configs.total_profit` is only *read*
(`BotConfig.php:295,301`) and is aggregated on the fly from `completed_trades`
(`CompletedTrade::getPerformanceStats/getDailyReport` `sum('profit')`,
`CompletedTrade.php:492,518`); no code path in the cycle flow writes it. So the
`completed_trades` row is the single source of truth for per-cycle realized
profit.

---

## 2. The EXACT engine profit formula, verbatim

All of the following is `CompletedTrade::createFromOrders()`
(`app/Models/CompletedTrade.php`). Inputs are pulled as canonical decimal strings
and every operation goes through `App\Support\Money` (bcmath, default scale 20,
trailing zeros trimmed; `div` truncates, does not round — `Money.php:106-112`).

**Inputs (`:359-365`):**
```php
$buyPrice   = Money::normalize($buyOrder->price);    // IRT, DECIMAL(20,0) string
$sellPrice  = Money::normalize($sellOrder->price);   // IRT, DECIMAL(20,0) string
$buyAmount  = Money::normalize($buyOrder->amount);   // BTC, decimal:8
$sellAmount = Money::normalize($sellOrder->amount);  // BTC, decimal:8
$amount     = Money::min($buyAmount, $sellAmount);   // book on the matched qty
```

**Gross profit (`:380`):**
```php
$grossProfit = Money::mul(Money::sub($sellPrice, $buyPrice), $amount);
//            = (sellPrice − buyPrice) × amount
```
→ **Gross is BTC-quantity × price-difference on the SAME quantity.** It is *not*
buy-notional-only and *not* an approximation of `notional × spacing%`. Because
the paired sell was created at `buyPrice × (1 + spacing/100)` on the same qty
(§1 step 3), this is algebraically `≈ buyNotional × spacing/100`, but the code
computes the exact price delta, not the spacing shortcut.

**Fee (`:394-399`):**
```php
$feeBps  = $buyOrder->botConfig?->fee_bps ?? config('trading.exchange.fee_bps', 35); // :394
$feeRate = Money::div((string) $feeBps, '10000');            // 35 bps → "0.0035"    :395

$buyNotional  = Money::mul($buyPrice, $amount);             // :397
$sellNotional = Money::mul($sellPrice, $amount);            // :398
$totalFee     = Money::mul($feeRate, Money::add($buyNotional, $sellNotional)); // :399
//            = feeRate × (buyNotional + sellNotional)
```

**Net profit (`:402`):**
```php
$netProfit = Money::sub($grossProfit, $totalFee);
//         = grossProfit − totalFee
```

**Profit percentage (`:406-408`):** gross relative to **buy** notional, guarded
against a zero denominator:
```php
$profitPercentage = Money::isZero($buyNotional)
    ? '0'
    : Money::mul(Money::div($grossProfit, $buyNotional), '100');
```

**Consolidated engine formula (real):**
```
amount   = min(buyQty, sellQty)                          # normally equal
gross    = (sellPrice − buyPrice) × amount
feeRate  = fee_bps / 10000
fee      = feeRate × (buyPrice×amount + sellPrice×amount)
net      = gross − fee            # stored in completed_trades.profit
```

**Persisted columns (`:413-436`)** — schema from
`create_completed_trades_table` + `add_advanced_metrics...`:
`profit` = `$netProfit` (DECIMAL(20,0) → rounded to whole IRT on store),
`fee` = `$totalFee` (DECIMAL(20,0) → whole IRT), `gross_profit` = `$grossProfit`
(DECIMAL(20,8)), `net_profit` = `$netProfit` (DECIMAL(20,8)),
`profit_percentage` (DECIMAL(10,4)), `amount` (DECIMAL(20,8)),
`buy_price`/`sell_price` (DECIMAL(20,0)).

---

## 3. Fee mechanics (which side, which amount, IRT vs BTC, which source)

- **Which side:** **BOTH** legs. `totalFee = feeRate × (buyNotional +
  sellNotional)` (`:399`).
- **Which amount:** **each leg on its OWN notional** — the buy leg's fee is on
  `buyPrice × amount`, the sell leg's fee is on `sellPrice × amount` (`:397-398`).
  It does NOT charge both legs on the buy notional.
- **Fee source:** per-bot `bot_configs.fee_bps`, falling back to
  `config('trading.exchange.fee_bps', 35)` **only when the bot value is null**
  (`:394`). `fee_bps` is a `unsignedSmallInteger DEFAULT 35`
  (`2025_08_21_...:50`); a deliberate `0` is honored literally (the `??` fires
  only on null — see the comment at `CompletedTrade.php:388-393`). Config default
  = **35 bps = 0.35%** (`config/trading.php:33`).
- **IRT vs BTC:** fee is computed and charged **in IRT** (`feeRate × IRT
  notionals`) and stored in the IRT `fee` column. It is **NOT** modeled as
  receiving less BTC on the buy. The BTC `amount` booked equals the full filled
  quantity (`min(buyQty, sellQty)`, `:365`); no BTC is shaved for fees. So the
  engine's model is "buy/sell full BTC qty, pay a separate IRT fee on each leg's
  notional."
- **Slippage / other adjustments:** **none** in the recorded profit. The
  `slippage` column exists in `$fillable` (`CompletedTrade.php:39`) but
  `createFromOrders()` never sets it — it is absent from the `create([...])`
  array (`:413-436`), so it stores as `NULL`. `net` is exactly `gross − fee`,
  nothing else.

---

## 4. Does it account for buy-qty vs sell-value asymmetry? — **YES**

**Proof (two independent code facts):**

1. **The two legs share the exact same BTC quantity.** The sell continuation is
   created with `amount = $filledOrder->filled_amount ?? $filledOrder->amount`
   (`CheckTradesJob.php:734`) — the same quantity the buy filled. So the engine
   is genuinely "buy Q at low price, sell the SAME Q at high price," and
   `amount = min(buyQty, sellQty) = Q` (`CompletedTrade.php:365`).

2. **Both gross and fee are computed on real per-leg IRT values, not a single
   notional.**
   - Gross uses the price delta on Q: `(sellPrice − buyPrice) × amount`
     (`:380`) — the sell's larger IRT value is fully reflected because
     `sellPrice > buyPrice`.
   - Fee uses each leg's own notional: `feeRate × (buyPrice×amount +
     sellPrice×amount)` (`:397-399`) — the sell leg is charged on the **larger**
     `sellNotional`, not on the buy notional.

So the engine does the precise thing: `net = (sellPrice−buyPrice)·Q − feeRate·
(buyPrice·Q + sellPrice·Q)`. It does **not** approximate with a single
`notional × spacing%`. (The parallel log-only block at
`CheckTradesJob.php:955-957` uses the same both-leg-notional fee, so the log
matches the persisted number.)

---

## 5. Calculator's current formula vs engine's — **DIVERGES (calculator is optimistic)**

Calculator: `App\Filament\Pages\GridCalculator::calculate()`
(`app/Filament/Pages/GridCalculator.php:201-208`):
```php
$this->repNotional   = $repNotional;                               // first priced level's notional
$this->grossPerCycle = (int) round($repNotional * ($spacing / 100));            // :204
$this->feePerCycle   = (int) round(2 * $repNotional * ($this->feeBps / 10000)); // :206
$this->netPerCycle   = $this->grossPerCycle - $this->feePerCycle;               // :207
```
where `repNotional` is the first plan item whose `notional > 0` (`:192-199`), and
in budget mode every level shares `notional = budgetIrt / count` truncated
(`GridPlanner.php:176`). After sorting, buys sort first (`GridPlanner.php:126-133`),
so `repNotional` is a **buy** notional. `feeBps` comes from `plan['fee_bps']`
(= `config('trading.exchange.fee_bps')` = 35) (`GridCalculator.php:185`).

**Gross — matches (to rounding).** Let `N` = buy notional, `s` = spacing/100.
Calculator gross = `N·s`. Engine gross = `(sellPrice−buyPrice)·Q`. Since the
paired sell is `buyPrice·(1+s)` on the same `Q`, engine gross =
`buyPrice·s·Q = N·s`. Equal up to integer/tick rounding of the actual placed
sell price (`:723`) and the qty rounding in the planner.

**Fee — DIVERGES.** This is the real discrepancy:
- Calculator fee = `2·N·f` (both legs charged on the **buy** notional).
- Engine fee = `f·(N + N(1+s)) = f·N·(2+s)` (sell leg charged on the **larger**
  sell notional).
- **Engine fee − Calculator fee = `f·N·s = f · gross` — the engine charges an
  extra `feeRate × gross` that the calculator omits.**

**Direction & size.** The calculator **overstates net profit** by exactly
`feeRate × grossPerCycle` per cycle. Worked example (N = 3,000,000 IRT,
s = 1.5% = 0.015, f = 0.35% = 0.0035):

| Quantity | Calculator | Engine |
|---|---|---|
| gross | 3,000,000 × 0.015 = **45,000** | (buy×0.015) on same Q = **45,000** |
| sell notional | (implicitly N) 3,000,000 | 3,000,000 × 1.015 = **3,045,000** |
| fee | 2 × 3,000,000 × 0.0035 = **21,000** | 0.0035 × (3,000,000+3,045,000) = **21,157.5** |
| net | 45,000 − 21,000 = **24,000** | 45,000 − 21,157.5 = **23,842.5** |

Calculator overstates net by **157.5 IRT** per cycle (= `f·gross = 0.0035 ×
45,000`), i.e. ~0.66% of the true net or 0.35% of gross. Small in relative
terms, but it is a **systematic upward bias** that grows with spacing and with
the number of levels summed. A "full grid round" figure that must match the
engine should use the engine's `f·N·(2+s)` fee, not `2·N·f`.

(Note: the calculator's `feeBps` source is global config, while the engine
prefers per-bot `bot_configs.fee_bps`. For the default bot they are both 35, but
if a bot overrides `fee_bps` the calculator — which has no bot context — will
also diverge on the rate. See Open questions.)

---

## 6. "Full grid round" = sum-of-levels vs single × N — **SUM-OF-LEVELS is correct**

**Answer: compute each priced level's own cycle net with the engine's exact
formula and SUM them. Do not use (single-cycle net × number of levels).**

Why, from the plan the calculator already builds
(`GridPlanner::plan` items, each with `price`, `quantity`, `notional`):

- **Per level the real inputs differ.** In budget mode
  (`GridPlanner.php:174-180`): `notional = budgetIrt / count` (equal per level,
  truncated at scale 0), but `qty = notional / price` — so **qty differs by
  level** (higher-priced sell levels get fewer BTC), and each level's paired
  price is tick-rounded independently (`:723`, `roundToTick` `:227-238`). The
  faithful per-level net is `net(level) = gross(level) − f·(buyNotional(level) +
  sellNotional(level))` using that level's own numbers.

- **In *budget* mode the two approaches happen to nearly coincide** — because
  `notional` is held equal across levels, each level's net is
  `N·s − f·N·(2+s)`, the same for every level, so `sum ≈ single × count`. But
  this equality is only approximate (per-level tick/qty rounding shifts each
  cycle by a few IRT) and it is **mode-dependent**: it does NOT hold in
  `fixedQty` mode (`GridPlanner.php:171-173`) or balance-aware sell sizing
  (`presetSellQty`, `:165-170`), where qty is fixed and **notional = price × qty
  differs by level**, making per-level net genuinely different. Summing is the
  only formulation that is correct in every mode.

- **Additional correctness point — which levels are "cycles".** Only priced
  levels with `notional > 0` (and above `min_order_value_irt`) actually become
  orders; a robust total should sum over exactly the plan's priced buy↔sell
  round-trips, not a naive `levels` count. In `both` mode `perSide =
  levels/2` (`GridPlanner.php:84`), so a full round is `perSide` cycles, not
  `levels`. (This count is a UI-semantics decision — flagged in Open questions —
  but it further argues against a blind `single × levels`.)

**Recommendation for the new UI figure:** iterate `plan['items']`, and for each
priced level compute the engine net with `net = notional·s − f·(notional +
notional·(1+s))` (equivalently `net = gross − f·(buyNotional + sellNotional)`
using that level's real buy/sell notionals), then sum. This reproduces the
engine's accounting exactly and is mode-agnostic.

---

## 7. Verification against bot 46's real recorded cycle

**I could not read the DB in this container** (no `vendor/`, no `.env`, no
configured DB, `ext-bcmath` absent — all verified: `php -m` shows no `bcmath`;
`php artisan tinker` aborts on the missing `vendor/autoload.php`). No SELECT was
possible, and no INSERT/UPDATE/DELETE was attempted. So this section gives the
**exact hand-reconstruction the host verifier should run** against the real row,
plus the precise expected relationships between the already-stored columns (which
require no market data — they are internally consistent by construction).

**Step 1 — pull the row (read-only) on the host:**
```sql
SELECT id, buy_order_id, sell_order_id, buy_price, sell_price, amount,
       gross_profit, fee, net_profit, profit, profit_percentage
FROM completed_trades
WHERE bot_config_id = 46
ORDER BY id
LIMIT 1;
```
Also fetch the fee rate actually used: `SELECT fee_bps FROM bot_configs WHERE
id = 46;` (if NULL, the engine used `config('trading.exchange.fee_bps') = 35`).

**Step 2 — recompute from `buy_price`, `sell_price`, `amount`, `fee_bps` and
check against the stored `gross_profit`/`fee`/`net_profit`/`profit`:**
```
feeRate      = fee_bps / 10000
gross_check  = (sell_price − buy_price) × amount
fee_check    = feeRate × (buy_price × amount + sell_price × amount)
net_check    = gross_check − fee_check
```
Expected exact matches (these are pure functions of the four stored inputs — no
market data needed, so they must reproduce regardless of price history):
- `gross_check` == stored `gross_profit` (DECIMAL(20,8)).
- `fee_check`   == stored `fee`, remembering `fee` is DECIMAL(20,0) so the store
  **rounds to whole IRT** — compare `round(fee_check)` to the stored `fee`.
- `net_check`   == stored `net_profit` (DECIMAL(20,8)); and `round(net_check)`
  == stored `profit` (DECIMAL(20,0), whole IRT).
- `profit_percentage` == `gross_check / (buy_price × amount) × 100` (DECIMAL(10,4)).

If all four reproduce → the §2 formula is confirmed. If `fee`/`profit` are off by
< 1 IRT it is just the DECIMAL(20,0) rounding on store (expected); anything
larger is a real mismatch and should be reported verbatim, not massaged.

**Cross-check that needs nothing but the row itself** (internal identity that the
engine guarantees): `net_profit == gross_profit − fee_exact`, where `fee_exact =
feeRate × (buy_price + sell_price) × amount`. And `gross_profit` should be
positive for a genuine buy-low/sell-high cycle (`sell_price > buy_price`).

**Worked numeric template** (substitute the real row; illustrative values):
```
buy_price = 60,000,000,000 ; sell_price = 60,900,000,000 ; amount = 0.00005 ; fee_bps = 35
gross = (60.9e9 − 60.0e9) × 0.00005            = 900,000,000 × 0.00005 = 45,000
fee   = 0.0035 × (60.0e9+60.9e9) × 0.00005     = 0.0035 × 120.9e9 × 0.00005
      = 0.0035 × 6,045,000                      = 21,157.5  → stored fee = 21,158 (rounded)
net   = 45,000 − 21,157.5                       = 23,842.5  → stored profit = 23,843 (rounded)
```
The host should replace these with bot 46's real `buy_price/sell_price/amount/
fee_bps` and confirm the stored `gross_profit/fee/net_profit/profit` match.

---

## 8. Open questions / anything not resolvable by reading

1. **Could not verify against live data here.** No DB/`vendor`/bcmath in this
   container (see §7). The formula is confirmed by source reading and by
   internal-consistency reconstruction, but the final numeric confirmation
   against bot 46's stored row must be run on the host with the SQL in §7.

2. **Fee-rate source for the calculator.** The engine prefers per-bot
   `bot_configs.fee_bps` (`CompletedTrade.php:394`); the calculator page has no
   bot context and uses global config `fee_bps` (`GridCalculator.php:185`). For
   the default (35) they agree, but a bot with an overridden `fee_bps` would make
   the UI figure diverge on the rate too. Decide whether the new figure should
   accept a `fee_bps` input / bot selection.

3. **"Full grid round" cycle count is a UI-semantics choice.** §6 shows the
   arithmetic (sum the priced levels' engine-formula nets). But whether a "full
   round" means `perSide` cycles (each buy round-tripping once) or all priced
   levels, and how partially-priced / below-min levels are treated, is a product
   decision not fixed by the engine code.

4. **Gross rounding vs the spacing shortcut.** The engine's gross uses the
   **actually placed** integer sell price
   (`newPrice = (int) round(buyPrice·(1+s))`, `CheckTradesJob.php:723`), which
   can differ from `buyPrice·(1+s)` by up to ~0.5 IRT before tick effects, and
   sell prices are not tick-aligned in the continuation path (the initial-grid
   planner tick-aligns, `GridPlanner.php:110`, but the continuation uses raw
   `round`). For a to-the-rial UI match the calculator would need to mirror this
   exact rounding per level; for a headline figure the `N·s` approximation is
   within a rial or two per cycle.

5. **DECIMAL(20,0) store rounding.** `profit` and `fee` persist to whole IRT
   (schema), while `gross_profit`/`net_profit` keep 8 dp. The half-up rounding of
   `net` into `profit` (`CompletedTrade.php:420`, cast `decimal:0`) means the
   headline `profit` and the high-precision `net_profit` can differ by < 1 IRT.
   Decide which the UI should mirror (recommend `net_profit` semantics, then
   round for display to match `profit`).

6. **Partial fills.** Today pairs are only created for fully-filled orders so
   both legs are equal, and `amount = min(buyQty, sellQty)` handles any future
   drift (`CompletedTrade.php:341-377`). If a partial-fill flow ever ships, the
   booked quantity is the matched min and the residual is intentionally not
   tracked — the UI's per-cycle figure assumes equal legs, which holds today.
