# clientOrderId (v2)

Every order the bot sends to Nobitex carries a `clientOrderId`. It is how the
reconciler finds an order whose placement response was lost.

## Format

```
g{botId}-{gridOrderRowId}        e.g.  g47-123456   g3-98
```

The format is defined in one place: `GridOrder::clientOrderIdFor(GridOrder $row)`.

- **One id per order intent, forever.** The id comes from the `grid_orders` row
  id (the primary key), so two intents never share an id. This holds even when
  two exits land on the same price.
- **The same id across retries of the same intent.** A retry reuses the same
  row, so it sends the same id. The reconciler looks the order up by that id.
- **Always valid for Nobitex.** Nobitex requires at most 32 characters,
  `[A-Za-z0-9-]` only, and no two OPEN orders with the same id. A bot id of up
  to 6 digits plus a row id of up to 20 digits gives at most 28 characters.
  `clientOrderIdFor` checks the result. If the check ever fails, it throws
  `DefinitiveInvalidArgumentRejection('LocalValidation')`.

## Order of operations (every placement path)

This applies to pair exits (`CheckTradesJob::createPairOrderLocked`), the
initial grid and rebalances (`GridOrderExecutor::applyForBot`), in both
simulation and live mode:

1. Create the intent row with `client_order_id = NULL`. A live row starts as
   `pending`; a simulation grid row starts as `placed`.
2. In the same transaction, set `client_order_id = clientOrderIdFor(row)`.
   `GridOrder::createIntent()` does steps 1 and 2. The UNIQUE index on
   `client_order_id` allows any number of NULLs on MySQL and SQLite.
3. Commit, then call the exchange.

Nothing ever gives an existing intent row a new id.

## Duplicate guards

- **Pair exits: one exit per fill.** These guards prevent a second exit:
  - the `pair-order:{fill}` cache lock;
  - the `lockForUpdate` re-read of `paired_order_id` / `exit_state`;
  - the back-link, which commits together with the intent row.
- **Initial grid / rebalance:** no second ACTIVE order for the same
  bot + side + price. ACTIVE means `pending`, `placed`, `partially_filled` or
  `submission_unknown` (`GridOrderExecutor::ACTIVE_STATUSES`). A level whose
  earlier order is `filled` or `cancelled` can be placed again.
  A short per-level cache lock (`grid-level:{bot}:{side}:{price}`) covers the
  dedup check and the intent-row insert. The lock is released once the row has
  committed, before the exchange call. Without it, two concurrent runs could
  both place the same level, because the UNIQUE index no longer catches that
  case now that each intent has its own id. If the lock is busy, the level is
  skipped (`DEDUP_LOCK_BUSY`).

The old scheme built the id from the price: `grid:{bot}:{SYMBOL}:{side}:{price}`.
It broke the Nobitex rule (it used `:` and could be longer than 32 characters).
It also caused a bug: an exit at a price that had been used before was skipped
forever as a duplicate, or hit the UNIQUE index.

## Send boundary

`CreateOrderDto::toApiPayload()` and `NobitexService::placeOrder()` validate
every clientOrderId with `GridOrder::isValidNobitexClientOrderId()`. An invalid
id throws `DefinitiveInvalidArgumentRejection('LocalValidation')` before any
HTTP call. Callers then cancel or block the row; they never park it as
`submission_unknown`.

## Duplicate-id answer from Nobitex

Nobitex answers `DuplicateClientOrderId` (the margin docs spell it
`duplicateClientOrderId`) when an OPEN order with that id already exists.
`NobitexService` maps both spellings to `DuplicateClientOrderIdException`. This
exception is deliberately not a definitive rejection. Each intent has its own
id, so a duplicate can only mean that this same intent was already accepted.
The row goes to `submission_unknown`, and `SubmissionReconciler` resolves it by
looking up the clientOrderId. The order is never re-sent, under this id or any
other.

## WebSocket events

`ExchangeWsEventRecorder` matches a private order event by `nobitex_order_id`
first. If that finds nothing, it tries `client_order_id`, but only against a
row whose `nobitex_order_id` is not stored yet (the WS event arrived before the
REST response was saved). The match is read-only.

## Legacy rows

Rows created before v2 keep their `grid:…` ids; existing rows are not rewritten.
Legacy and v2 ids cannot collide, because a v2 id never contains `:`. The send
boundary refuses a legacy id, so a resend of a legacy live row is refused
locally (definitive). Both bots ran in simulation when v2 shipped, so no live
legacy rows exist.
