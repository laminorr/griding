# Classic-grid re-arm («چراغ‌ها»)

Per-bot flag `bot_configs.rearm_exits` (default **OFF**; the migration never turns it on).
UI: bot form → «فعال‌سازی دوباره‌ی سطح‌ها (گرید کلاسیک)». Monitoring info line: «گرید کلاسیک: روشن/خاموش».

## Behaviour (flag ON)

Grid order A fills → exit B (unchanged) → B fills → CompletedTrade booked (unchanged) → **re-arm C**:

| | |
|---|---|
| side | the cycle's original leg (A's side) |
| price | the chain root's price, copied (never recomputed from B) |
| role | `rearm` — a grid order: its fill gets a `cycle_exit` through the normal pairing path |
| sell size | `floor_qty(backing + base_dust)`, backing = BTC the parent sell removed; dust moves through the ledger (`exit_dust_delta` on the re-arm row) |
| buy size | `min(root level qty, floor_qty(exit-sell proceeds / cost))` — only the proceeds, never other capital |

Chain: A → B → C → D → E … on the same level for as long as the level stays in the current grid.

Code: `App\Services\GridRearmer` (called from `CheckTradesJob::processBot` — poller + simulation — and
`CheckTradesJob::processSingleOrder` — W4). Sizing: `ExitSizer::reserveRearmSell` / `sizeRearmBuy`.

## Invariants

1. One exit fill → at most one re-arm: per-exit `Cache::lock("rearm:{exit}")`, `lockForUpdate` re-read of
   `grid_orders.rearm_order_id` / `rearm_state`, intent row + back-link in one transaction, and a UNIQUE
   index on `grid_orders.rearm_exit_order_id`.
2. One live order per price level: a level with any `pending/placed/partially_filled/submission_unknown`
   row at that price is skipped (`REARM_SKIPPED_LEVEL_BUSY`); the check runs under the same
   `grid-level:{bot}:{side}:{price}` lock GridOrderExecutor uses.
3. Exits are never cancelled (unchanged; `rearm` is not a protected role, so a rebuild replaces unfilled re-arms).
4. Profit is booked only on cycle close.
5. Re-arm sell above its exit buy, re-arm buy below its exit sell — else ERROR `REARM_REFUSED_PRICE_INVARIANT`.

## No re-arm when

bot inactive · kill switch / stop-loss / drawdown tripped (`KillSwitchService::checkAndTrigger`) ·
bot `last_error_code = EXIT_BLOCKED` or exit/parent `exit_state = blocked` · flag off · level not in the
current grid generation · below `min_order_value_irt` · exit filled more than
`trading.rearm.max_fill_age_minutes` (60) ago (so switching the flag on never re-arms old cycles).
Every refusal is final for that exit: `rearm_state = 'skipped:<REASON>'`, WARNING `REARM_SKIPPED_<REASON>`.

## Generation rule (no resurrection of old levels)

`bot_configs.grid_generation` is bumped by `GridOrderExecutor::applyForBot` on every grid build
(`initial_grid` / `rebalance` with a non-empty diff). New grid rows are stamped with it, and the open grid
rows the rebuild **kept** (they match the new plan) are re-stamped. A re-arm inherits the generation of the
order that opened the cycle and is placed only when that equals the bot's current generation. So a cycle
opened before a rebuild still closes and books its trade, but its level stays dark. Rows from before the
migration (`grid_generation` NULL) count as current only while no build has happened since the deploy and
their price is a level of the current grid batch (`OrderRegistry::currentGridLevelPrices`).

## Logs

- `REARM_PLACED {bot_id, exit_order_id, rearm_order_id, root_order_id, side, price, amount}`
- `REARM_SIZED`, `GRID_GENERATION_STARTED` (info)
- `REARM_SKIPPED_{LEVEL_BUSY|MIN_NOTIONAL|BOT_INACTIVE|KILL_SWITCH|EXIT_BLOCKED|OLD_GENERATION|NOT_GRID_LEVEL|NO_PARENT|NO_ROOT|REJECTED}` (warning)
- `REARM_REFUSED_PRICE_INVARIANT`, `REARM_PLACE_FAILED`, `REARM_INTENT_FAILED`, `REARM_ERROR` (error)

`bot:audit` §2 prints the re-arm count, exit decisions, logged skips and invariant 1/2/5 violations
(`REARM_DUPLICATE`, `REARM_LEVEL_NOT_FREE`, `REARM_PRICE_INVARIANT`, `REARM_LEVEL_DRIFT`); §5 replays the
re-arm dust moves; §9's classic replay follows the same rules (fees ignored in re-arm sizing, gates not modelled).
