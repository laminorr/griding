# Nobitex WebSocket consumers (W0–W4)

Two long-running processes, each kept alive by a cron script that restarts it within a minute or two when the process is gone:

| Process | Service | Keep-alive | Channels |
|---|---|---|---|
| `nobitex:ws-consumer` | `NobitexWebSocketService` | `scripts/ws-keepalive.sh` | public orderbooks and candles |
| `nobitex:ws-private` | `NobitexPrivateWsService` | `scripts/ws-private-keepalive.sh` | `private:orders#…`, `private:trades#…` |

Stages:

- **W0**: hardened the public market-data feed (heartbeat keys, `WsFeedHealthCheck`).
- **W1/W2**: candle (OHLC) channels on the same public connection, and the live chart.
- **W3**: the private consumer. Observe-only. `ExchangeWsEventRecorder` stores every order and trade event in `exchange_ws_events`.
- **W4**: when `NOBITEX_WS_ACT_ON_EVENTS=true`, an actionable Spot order event dispatches `ProcessOrderEventJob`. That job re-checks the order over REST through `CheckTradesJob::processSingleOrder()`. The event is only a trigger; the REST status is the truth. The minute poller stays on as the safety net.

## Read timeout and liveness

Centrifugo sends a `{}` ping about every 25 s and expects `{}` back. The private channels are almost always silent, so on the private socket those pings are often the only frames.

- **Read timeout**: `trading.websocket.read_timeout_seconds` (`NOBITEX_WS_READ_TIMEOUT_SECONDS`, default **60**). Both consumers use it. It must be longer than the ping interval plus a margin. The old hard-coded 25 s raced the ping on the silent private socket, which caused `Client read timeout` drops every 1–3 minutes.
- **Private liveness rule**: on the private socket, a read timeout is not a drop by itself. The loop tracks the time of the last frame of any kind, pings included. It keeps reading until no frame has arrived for `trading.websocket.private_max_silence_seconds` (`NOBITEX_WS_PRIVATE_MAX_SILENCE_SECONDS`, default **90**). Then it reconnects, and the log reason is `no frames for Ns`. Each read's timeout is capped at whatever is left of that silence budget, so the reconnect happens on time (60 s, then 30 s). If the client closed the socket on the timeout, that is a drop straight away.
- **Token refresh** is checked after every frame and after every tolerated read timeout. It is sent 120 s before the connection token's ttl runs out. On a silent channel the pings make sure it is sent within about one ping interval of becoming due.
- **Single-instance lock**: each consumer's cache lock TTL is now `max(60, read_timeout + 15)`, which is 75 s by default. It can no longer lapse while `receive()` blocks. After a `pkill`, the cron restart can therefore take one extra minute while the old lock expires.
- **Public consumer**: only the timeout changed. A read timeout there still reconnects, because orderbook pushes are constant and a silent public socket is a dead one.

### Public reconnect logging

`nobitex:ws-consumer` now follows the same policy as the private consumer.
Every reconnect logs
`[WS] Connection dropped; reconnecting {error, attempt, wait, reconnects_last_hour}`.
This replaces the old `ERROR [WS] Crash` line.

- **Expected server drop**: `Empty read; connection dead?`, or a close frame,
  which is logged as `Socket closed by server (close frame)`. Logged as
  **WARNING**. Nobitex recycles public connections almost every night around
  03:50–04:05 Tehran.
- **Any other drop of a healthy connection**: **ERROR** the first time in an
  hour, **WARNING** after that.
- **Failed reconnect attempt** (`attempt > 1`, the new connection never
  received a frame): **ERROR**. The attempt counter now goes back to 1 after a
  healthy connection. It used to count up for the life of the process
  (`attempt 4`, `8`, …).
- **Flapping**: more than `trading.websocket.private_flap_reconnects_per_hour`
  reconnects in an hour also logs **ERROR** `WS_PUBLIC_FLAPPING`, at most once
  an hour. This is the same threshold the private consumer uses.
- **Symbols**: the command filters its `symbols` argument through
  `trading.exchange.allowed_symbols` (`TRADING_SYMBOLS_ALLOWED`). Disallowed
  symbols are logged once per start in
  `WARNING [WS] Skipping symbols not in trading.exchange.allowed_symbols`, then
  never seeded or subscribed. The REST re-seed still runs on every reconnect,
  for the allowed symbols.

### Private reconnect logging

All reconnects log `[WS-PRIVATE] Connection dropped; reconnecting {error, attempt, wait, reconnects_last_hour}`.

| Case | Level |
|---|---|
| First genuine drop in an hour (socket error, read failure, `no frames for Ns`) | ERROR |
| Each further genuine drop within that hour | WARNING |
| Planned reconnect (token expired, server `disconnect` push, refresh rejected) | WARNING |
| A reconnect attempt that itself failed (`attempt` > 1) | ERROR |
| More than `trading.websocket.private_flap_reconnects_per_hour` (`NOBITEX_WS_PRIVATE_FLAP_RECONNECTS_PER_HOUR`, default 10) reconnects in a rolling hour | ERROR `WS_PRIVATE_FLAPPING`, at most once an hour |

### Centrifugo history / recovery

The `[WS-PRIVATE] Subscribed` line now logs the subscribe reply's `recoverable`, `positioned`, `epoch`, `offset` and `reply_keys`. If the server reports `recoverable: true` with an epoch and offset, a later change could subscribe with `recover: true` and the last offset, and receive the publications missed while reconnecting. That is not built. Until then, the minute poller covers any gap.

## W4 API-lag re-check

REST can lag the WS by about a second. A `Done` event can arrive while `POST /market/orders/status` still says `ACTIVE`. Live example: bot 48, order 278. W4 then did nothing, and the poller paired the order 70 s later.

`ProcessOrderEventJob` now re-checks when **all** of these hold:

- the event status is terminal (`Done` or `Canceled`). The recorder never dispatches `Failed`, and `Inactive` is not terminal.
- the REST status after processing is still non-terminal (not `FILLED`, `CANCELED` or `ERROR`).
- nothing was paired.
- the order is still `placed` or `partially_filled` locally.

A busy `order-status:{id}` lock or a missing REST status does not trigger a re-check: the poller already has that order.

- **Delays**: `trading.websocket.api_lag_recheck_delays` (`NOBITEX_WS_API_LAG_RECHECK_DELAYS`, default `2,4,8` seconds). Each re-check logs `WS_EVENT_API_LAG_RECHECK {attempt, delay, api_status}`. After the last one, the job logs `WS_EVENT_API_LAGGING_GAVE_UP` and leaves the order to the minute poller. An empty list turns re-checks off.
- **Mechanism**: the job dispatches a new job with `recheck = n` and `->delay(d)`. It does not use `release()`, which would use up `$tries` (kept for real failures). `ShouldBeUniqueUntilProcessing` releases the unique lock before `handle()` runs, so the re-dispatch takes a fresh lock. If a new event's job is already queued for that order, the re-dispatch is absorbed and the queued job does the REST check. On the database queue, the delay becomes `available_at = now + d`. `queue:work database --sleep=1` polls every second, so a re-check runs within about 1 s of being due. `ProcessOrderEventRecheckTest` checks this against a real `jobs` table and worker.
- **One fill, one exit**: a re-check runs the same guards as the first run (bot active, not simulation, order not terminal locally). It uses the same `order-status:{id}` lock, the same `pair-order:{id}` lock, and the same `paired_order_id` re-read under `lockForUpdate`. Tests race the re-check against the minute poller in three orders, and each creates exactly one exit.
