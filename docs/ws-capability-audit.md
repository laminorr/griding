# Nobitex WebSocket capability audit (read-only)

**Bottom line:** I could not count Nobitex's public and private channels (N and M) because the official docs could not be fetched in this session (see "Source status"). Griding uses **4 channel families**: 2 public (`public:orderbook-{SYM}`, `public:candle-{SYM}-{RES}`) and 2 private (`private:orders#…`, `private:trades#…`). The most valuable unused capabilities for trend and regime detection are (1) **candle resolutions we already support but don't subscribe to or compute on**, mainly 5/30/240, and the **volume `v` we already receive and throw away**, and (2) **a public trades feed with taker side, if Nobitex documents one**. The docs need to confirm (2) before anyone builds it.

> Audit date: 2026-10-04. Branch `claude/ws-capability-audit`. Docs and code reading only. No live WS connection and no private API calls were made.

---

## Source status (read this first)

| Source | Result |
|---|---|
| `https://apidocs.nobitex.ir` (WS section, REST market data, `/v2/trades/{symbol}`) | **NOT FETCHED.** The session's egress proxy blocks the host (`curl` CONNECT 403; WebFetch `EGRESS_BLOCKED`). |
| `github.com/nobitex/docs-api` (official docs source repo) | **NOT FETCHED.** Attaching the repo to the session was refused by the session's permission policy. |
| Web search | Found nothing usable. It returned no channel list or payload schema. |

Because of this, every statement below about **what Nobitex offers** comes from one of two places, and each one is labelled:

- **[code]**: what our code subscribes to, parses, or documents in comments. Those comments sometimes cite the docs, e.g. "per Nobitex docs" at `NobitexWebSocketService.php:206-207`, `:356-357`. This is evidence of channels that work in production. It is **not** a full inventory.
- **[test fixture]**: field names in our test payloads (`tests/Feature/Services/ExchangeWsEventRecorderTest.php:59-96`, `NobitexWebSocketServiceTest.php:33-39`). They were probably modelled on real events, but this audit did **not** check them against the docs.
- **UNVERIFIED**: anything that needs the docs. Per the task rules, no channel names or payload fields have been guessed.

**To finish section A**, re-run this audit somewhere `apidocs.nobitex.ir` is reachable, or give the session read access to `nobitex/docs-api`.

---

## A. Full channel inventory

### A.1 Channels known from our code (confirmed working in production)

| Channel pattern | Pub/priv | Publishes | Payload fields we know of (units) | Trigger | Limits | Source |
|---|---|---|---|---|---|---|
| `public:orderbook-{SYMBOL}` (e.g. `public:orderbook-BTCIRT`) | public | L2 orderbook snapshot | `asks`, `bids`: `[[price, amount], …]` as strings; `lastTradePrice`; `lastUpdate` (ms). **Price in RIAL** for IRT markets (BTCIRT ≈ 216,390,000,000). Amount in **base** asset. | **Only on change** (`NobitexWebSocketService.php:19-20`, `:206-207`), so we seed from REST `/v3/orderbook/{sym}` on every connect | Depth per side: UNVERIFIED | [code] `NobitexWebSocketService.php:354`, `:618-647`; [test fixture] `NobitexWebSocketServiceTest.php:33-39` |
| `public:candle-{SYMBOL}-{RESOLUTION}` | public | Current (open) candle | `t` (unix s, candle open), `o`,`h`,`l`,`c` (**TOMAN** for IRT markets, verified on host: `CandleService.php:23-31`), `v` (**base-asset volume**) | Per update of the open candle. Exact cadence UNVERIFIED. | Resolution set: our code assumes it is the same as REST UDF: `1,5,15,30,60,180,240,360,720,D,1D,2D,3D` (`NobitexService.php:54`). Not checked against WS docs. | [code] `NobitexWebSocketService.php:417`, `:539-564` |
| `private:orders#{websocketAuthParam}` | private (JWT from `GET /auth/ws/token/`) | The user's own order lifecycle events | [test fixture] `orderId`, `tradeId`, `clientOrderId`, `srcCurrency`, `dstCurrency` (`rls`), `eventTime` (ms), `lastFillTime`, `side`, `status` (`New`/`Active`/`Done`/`Canceled`/`Inactive`/`Failed`), `fee`, `price` (RIAL), `avgFilledPrice`, `tradePrice`, `amount` (base), `tradeAmount`, `filledAmount`, `param1`, `orderType`, `marketType`. Failure variant: `status:"Failed"`, `code`, `message`, `clientOrderId`. | On each order state change | Token ttl ≈1200 s, refreshed 120 s early (`NobitexPrivateWsService.php:29-33`, `:72`) | [code] `NobitexPrivateWsService.php:68`, `:225-237` |
| `private:trades#{websocketAuthParam}` | private | The user's own fills | [test fixture] `id`, `orderId`, `srcCurrency`, `dstCurrency`, `time` (ms), `timestamp` (deprecated ISO string), `type` (`Buy`/`Sell`), `price` (RIAL), `amount` (base), `total`, `fee`, `isMaker` | Per fill | as above | [code] `NobitexPrivateWsService.php:69`, `ExchangeWsEventRecorder.php:228-245` |

Protocol: Centrifugo JSON at `wss://ws.nobitex.ir/connection/websocket`. Server sends a `{}` ping about every 25 s and expects `{}` back. Several messages can arrive in one frame, separated by newlines. Subscribe frames are `{"id":N,"subscribe":{"channel":…}}`. Delta/fossil compression is not requested. (`docs/websocket.md`, `NobitexPrivateWsService.php:27-37`.)

### A.2 Channels that may exist but are UNVERIFIED

These are the questions the docs must answer. I have not guessed any of them:

- **Is there a public TRADES channel?** **UNKNOWN (docs not fetched).** Nothing in our code subscribes to or mentions one. If it exists, check that each trade carries: **taker side** (buy/sell), **price** (rial or toman?), **amount** (base), **timestamp** (ms?).
- **Is there a market stats / ticker channel** (24h change, 24h volume, best bid/ask)? **UNKNOWN.** Today we get 24h stats only from REST `/market/stats` (`NobitexService.php:1101`).
- **Other private channels** (e.g. wallet/balance, positions)? **UNKNOWN.** We subscribe to orders and trades only.
- **Documented limits**: max subscriptions per connection, connection rate limits, orderbook depth per publication. **UNKNOWN.**
- **REST public recent trades** (`/v2/trades/{symbol}`): **UNKNOWN**, including fields, units, how many trades it returns and whether it has a taker-side field. Our code never calls it (`grep` finds no `v2/trades`). The only trades endpoint we call is the **private, signed** `/market/trades/list` (`NobitexService.php:818`), used by `SubmissionReconciler.php:303`. That endpoint returns **our own** trades, not market trades.

---

## B. What Griding uses today

### B.1 `public:orderbook-{SYMBOL}`

- **Subscribed:** `app/Services/NobitexWebSocketService.php:350-367` (`subscribeOrderbooks`). The symbols come from the keepalive command line, `scripts/ws-keepalive.sh`: `nobitex:ws-consumer BTCIRT,ETHIRT,USDTIRT`.
- **Parsed:** `asks`, `bids` (normalised to `[price,amount]` strings, `:730-743`), `lastTradePrice` (also accepts `last`/`lastPrice`), and `lastUpdate` (`:618-629`). When there is no last price, the mid price is used instead (`:745-753`).
- **Dropped:** every other top-level key in the publication. With no doc schema, I can't say whether any exist. **No depth truncation is applied.** We store every level the channel sends.
- **Cached** (`cache.default` = **database** store, `config/cache.php:17`, so every put is a DB write):
  - `mdl:orderbook:{SYM}`: full snapshot, TTL `trading.cache.market_stats_ttl` (default **300 s**, `config/trading.php:255`).
  - `mdl:last_price:{SYM}` = `{price, ts}`, TTL `trading.cache.price_ttl` (default **30 s**, `config/trading.php:254`).
  - Writes are throttled per symbol to `cache_write_interval_ms` (default **1000 ms**, `config/trading.php:306`). In-memory snapshots update on every publication (`:640-644`, `:656-669`).
  - Heartbeats: `nobitex:ws:last_frame_at` and `nobitex:ws:last_publication_at`, written at most every 10 s, TTL 86400 (`:35-40`, `:711-725`).
- **Read by:** `MarketDataLayer::getLastPrice/getOrderBook/getSpread*` (`MarketDataLayer.php:79-216`). Its callers are:
  - `CheckTradesJob.php:214` (fill decisions on last price)
  - `KillSwitchService.php:95`
  - `GridPlanner.php:92` (grid mid)
  - `ReadMarketStatsJob.php:32-46`, which logs best bid/ask/spread/level counts as `MARKET_STATS` and stores nothing
  - `WebSocketHealthService.php:39` (age of `mdl:last_price:*`)
  - `WsFeedHealthCheck` (heartbeats)

### B.2 `public:candle-{SYMBOL}-{RESOLUTION}`

- **Subscribed:** `NobitexWebSocketService.php:374-422`. The list is `trading.websocket.candle_symbols` × `candle_resolutions` (`config/trading.php:317-318`).
  - **Configured default: symbols `BTCIRT` only; resolutions `1, 15, 60, D`.**
  - Resolutions not in `NobitexService::OHLC_RESOLUTIONS` are skipped with a warning.
- **Parsed:** `t`, `o`, `h`, `l`, `c`, `v` (`:539-564`), kept as decimal strings. Out-of-order publications that would move back to an older candle are ignored (`:571-576`).
- **Dropped:** any other key. The **close of the previous candle is never recorded**: only the latest candle per (symbol, resolution) is kept and overwritten, so finished WS candles are lost. History always comes from REST.
- **Cached:** `mdl:candle:{SYM}:{RES}` (`MarketDataLayer.php:30-36`), TTL **300 s** (`NobitexWebSocketService.php:45`), throttled at 1000 ms per key.
- **Read by:** `CandleService::liveCandle()` (`CandleService.php:218-242`), which overlays the live candle on REST `/market/udf/history` (cached 60 s under `candles:rest:{SYM}:{RES}:{count}`, countback ≤ 500). Its **only consumer is the panel chart**: `BotMonitoring.php:49` (`CHART_RESOLUTIONS = ['1','15','60','D']`) and `:350`. **No trading or risk decision reads candles or volume today.** I found no regime or trend filter in `app/` (`grep -i "regime|trend"` only hits display code in `CompletedTrade.php`).
- **Units:** o/h/l/c arrive in TOMAN and are multiplied by 10 to RIAL only on output (`CandleService.php:101-116`). `v` is base-asset volume and is left as is.

### B.3 `private:orders#…` and `private:trades#…`

- **Subscribed:** `NobitexPrivateWsService.php:224-237`. A separate process, `nobitex:ws-private`, kept alive by `scripts/ws-private-keepalive.sh`.
- **Parsed into columns** (`ExchangeWsEventRecorder.php:207-245`):
  - orders: `orderId`, `clientOrderId`, `status`, `marketType`, `eventTime`
  - trades: `id`, `orderId`, `time`
- **Dropped from columns** but **kept in full** in `exchange_ws_events.payload` JSON (`:106`). Nothing is lost.
  - orders: `side`, `price`, `amount`, `filledAmount`, `avgFilledPrice`, `tradePrice`, `tradeAmount`, `fee`, `orderType`, `tradeId`, `lastFillTime`
  - trades: `type`, `price`, `amount`, `total`, `fee`, `isMaker`, `timestamp`
- **Stored:** DB table `exchange_ws_events`, which is not a cache and has no TTL or pruning in this code (migration `2026_10_01_000001`). Unique keys stop duplicates on replay.
- **Read by:**
  - (W3) nobody for trading. The data is for observation and the `WS_PRIVATE_EVENT` log line.
  - (W4, only if `NOBITEX_WS_ACT_ON_EVENTS=true`, default **false**, `config/trading.php:330`) an actionable Spot **orders** event dispatches `ProcessOrderEventJob`, which re-checks the order over **REST**. The event is only a trigger. **`private:trades` events never trigger anything** (`ExchangeWsEventRecorder.php:125`).
- **Heartbeat/lock:** `nobitex:ws:private:last_frame_at` (TTL 30 days), `nobitex:ws:private:lock`.

---

## C. Gap analysis: unused capability

| Unused item | Available? | What it would measure | Notes for BTCIRT |
|---|---|---|---|
| **Candle `v` (volume)**, already received on WS and REST | **Yes [code]** | Volume regime (expansion vs contraction), volume-confirmed breakouts, relative volume against a rolling median | **Already in hand, at zero extra load.** We parse it and only the chart shows it. Best value for the effort. |
| **Candle resolutions 5, 30, 180, 240, 360, 720** | Yes on REST UDF [code]. On WS: assumed by our code, **UNVERIFIED** in docs | Multi-timeframe trend (e.g. 240 m/D slope as the regime, 15 m as the trigger); ATR-based volatility regime; ADX/efficiency ratio | A regime filter does **not** need the WS for these. REST history every few minutes is enough (60 req/min limit, cached). WS only adds a live view of the open candle. |
| **Closed-candle history from WS** | n/a: we overwrite | A local OHLCV store that doesn't depend on REST | Low value, because REST UDF already gives backfill. |
| **Orderbook depth beyond top-of-book** | **Yes [code]**: we keep every level the channel sends but only read level 0 | Bid/ask depth imbalance within ±x% of mid; liquidity walls; depth-weighted mid; spread regime | `ReadMarketStatsJob` logs only best bid/ask/spread and level counts. Nothing is persisted, so no history exists. On a shallow book, the imbalance is easy to spoof and changes with a few large resting orders. **Likely noisy as a trend signal.** Spread and depth are more useful as a **liquidity or risk** filter (e.g. widen or pause the grid when the spread is wide or the depth is thin). |
| **Spread time series** | Yes [code] (`MarketDataLayer::getSpreadPercent`) | Liquidity regime, stress detection | Computed on demand but never stored. Cheap to sample once a minute. |
| **Public trades: taker buy vs sell volume** (order-flow imbalance, CVD) | **UNVERIFIED.** It depends on a documented public trades channel or REST endpoint with a taker-side field | Order-flow imbalance, cumulative volume delta, aggressor pressure | If it exists, it is the only true order-flow signal available. On BTCIRT, retail flow with few trades per minute makes per-minute imbalance very noisy. It would only be useful aggregated over 15 m–4 h windows and as a confirmation, not a trigger. |
| **Trade frequency / size distribution** | **UNVERIFIED** (same dependency) | Activity regime; large-trade (whale) detection | Can be partly approximated from candle `v` (volume per bar) without trades. |
| **Market stats / ticker channel** (24h change, volume) | **UNVERIFIED** on WS. REST `/market/stats` exists [code] | 24h momentum, 24h volume regime | REST is enough for a filter that runs every minute or slower. |
| **`private:trades` as fill trigger** | **Yes, subscribed and recorded but not acted on** | Faster fill detection, exact fill price/amount/fee/maker flag per trade | See below. |

### Private channels: do we use all of them, and is WS faster than REST?

- We subscribe to the two private channels our code knows of. Whether other private channels exist is **UNVERIFIED**.
- **Speed:** WS is clearly faster. The REST poller (`CheckTradesJob`) runs **every minute** (`routes/console.php:31`), so detection takes 0–60 s, about 30 s on average. With W4 on, an orders event triggers a REST re-check within about 1 s. `docs/websocket.md` §"W4 API-lag re-check" records that REST **lags** the WS by about 1 s (bot 48, order 278: the poller paired it 70 s later). So WS events reach us ahead of REST, and REST is still treated as the source of truth.
- **Completeness:** each `private:trades` event carries per-fill `price`, `amount`, `fee`, `isMaker` [test fixture]. REST order status gives aggregates per order. Per-fill data is better for fee and maker/taker accounting (see `docs/fee-audit.md`). Today it only sits in `payload` JSON. **We have no data on whether the WS ever misses events.** Comparing `exchange_ws_events` against `grid_orders` fills would answer this. That check uses data already collected and is recommended before relying on WS-only fills.

---

## D. Data-history reality

| Signal | Backtestable from REST history? | Notes |
|---|---|---|
| OHLC + volume `v`, all UDF resolutions | **Yes**: `/market/udf/history` returns `t,o,h,l,c,v` (`NobitexService.php:1151-1231`), countback ≤ 500 per call, plus `from`/`to`/`page` | How far back it goes per resolution is UNVERIFIED. 500 × 1 m bars is about 8 h per call, so long 1 m histories need paging. |
| Volatility / trend / volume regime from candles | **Yes** (derived from the above) | Can be backtested now. |
| Orderbook depth, bid/ask imbalance, spread | **No.** We know of no historical orderbook endpoint, and we don't store snapshots | Live-only. Needs collecting from now on. |
| Taker buy/sell volume, trade count/size | **Probably no.** It depends on whether REST public trades exists (UNVERIFIED) and how many past trades it returns. Recent-trades endpoints usually return only the last N trades, not a range. | Assume live-only until the docs say otherwise. |
| Own fills (per-trade) | Yes: private `/market/trades/list`, plus `exchange_ws_events` since W3 | Not a market signal. |

**How long live-only signals take to become usable.** A regime filter needs to see several regimes: at least one trending period and one ranging period, ideally in both directions. In practice that means **at least 4–8 weeks** of collection before a threshold can be fitted, and **about 3 months** before you can trust it beyond one market phase. Order-flow features on a thin book need the longer end of that range.

**Storage estimate** (one row per sample, one symbol; trade counts are assumptions because BTCIRT trade frequency was not measured in this audit):

| Stream | Sampling | Rows/day |
|---|---|---|
| Orderbook snapshot (full) | every publication | unbounded / depends on update rate; **don't** |
| Orderbook summary (best bid/ask, spread, depth ±0.5%/±1% each side) | 1 s (current throttle) | 86,400 |
| same | 1 min | 1,440 |
| 1 m candle incl. `v` | per closed bar | 1,440 (REST covers this anyway) |
| Public trades, raw | per trade | ≈ trades/day. Measure first, e.g. with one hour of observe-only logging. At 1 trade/s it would be about 86,400/day. |
| Public trades, aggregated per minute (buy vol, sell vol, count, max size) | 1 min | 1,440 |

Recommendation: store **per-minute aggregates** (about 1,440 rows/day/stream, about 0.5 M rows/year). Don't store raw ticks or raw books.

---

## E. Hosting constraints (cPanel + cron keep-alive)

- The public consumer is one blocking PHP loop (`NobitexWebSocketService.php:219-256`). It must answer `{}` pings in time. Any slow work inside the loop, such as DB writes or REST calls, risks a ping timeout, which drops orderbook **and** candle feeds together.
- **Current DB-cache write load.** `cache.default` is the database store, so every write below is a DB write. Up to:
  - 3 symbols × 2 keys (`mdl:orderbook`, `mdl:last_price`) × 1/s = about **6 writes/s**
  - plus 4 candle keys at ≤ 1/s
  - plus a lock put **per frame** (`:221`)
  - plus heartbeats every 10 s

  That is already up to roughly 10+ writes/s, around 0.9 M/day, on a shared host.
- **Adding a trades channel** (if it exists) to the same connection:
  - **CPU and memory:** small. One JSON decode per trade, plus in-memory per-minute counters. The trade rate on BTCIRT is expected to be well under the orderbook publication rate (assumption; not measured).
  - **DB:** negligible **if** aggregated in memory and written once a minute, the same way candles are throttled. **Not acceptable** if each trade is inserted synchronously in the read loop.
  - **Risk to the existing flow:** (a) a slow insert or a burst blocks ping replies, so the whole public feed reconnects and reseeds; (b) the lock is put on every frame, so a higher frame rate means more lock writes. Rather than making it worse, throttle the lock refresh like the heartbeat. (c) The public consumer **does not check subscribe error replies**: a wrong channel name is logged only at `debug` as a generic `Frame` (`:281`). A mistyped trades channel would fail silently. Any new subscription needs to log errors the way the private consumer does (`NobitexPrivateWsService.php:437-441`).
- **Safer option:** run any trades/stats collector as a **third process** with its own keepalive and lock. Then a bug or a flood there cannot take down the orderbook feed that `CheckTradesJob`, `KillSwitchService` and `GridPlanner` depend on. The cost is one more long-running PHP process (about 30–50 MB RSS, typical for this stack; not measured). Check the cPanel process and memory limits before adding it.
- **Extra candle resolutions on WS** add one subscription each and one throttled cache key each, so the cost is low. But the regime filter doesn't need them on WS at all (see C).

---

## F. Recommendations (specification only, not implemented)

Ranked by value for a **regime/trend filter on BTCIRT**:

| # | Capability | Value | Effort | Risk | Notes |
|---|---|---|---|---|---|
| 1 | **Regime filter from REST UDF OHLCV** (e.g. 60 m/240 m/D): ATR% volatility regime, slope or efficiency-ratio trend, volume relative to a rolling median using `v` | **High.** Can be backtested today, and the data is already integrated | Low–Med: a scheduled job every 5–15 min using `CandleService`/`getOhlc`, cached | Low: no WS change, well inside 60 req/min | Do this first. Backtest it on history before wiring it into grid decisions. |
| 2 | **Persist a per-minute liquidity summary** (spread %, depth within ±0.5%/±1% each side, level counts) from the in-memory orderbook we already have | Med: liquidity and risk filter (pause or widen in thin or stressed books); weak trend signal | Low: extend the `ReadMarketStatsJob` idea to write one row a minute | Low: outside the WS loop | Start collecting **now**, because it can't be backfilled. Expect imbalance to be noise as a direction signal on BTCIRT. |
| 3 | **Act on `private:trades` / reconcile WS against REST fills** using data already in `exchange_ws_events` | Med for execution quality and fee accounting; none for regime | Low for the analysis query; Med for acting on it | Medium if acted on (double-processing); keep REST as the truth, as in W4 | First measure WS completeness against REST before relying on it. |
| 4 | **Public trades feed (taker side) → per-minute buy/sell volume, count, max size** | Potentially the only true order-flow signal. **Likely noisy on BTCIRT** except aggregated over 15 m–4 h | Med: first verify the channel exists in the docs, then build an in-memory aggregator and per-minute writes, preferably in a separate process | Med: risk to the orderbook loop if it shares the process (see E) | **Blocked on docs.** Needs at least 1–3 months of live collection before it can be evaluated. Measure the raw trade rate with a short observe-only run first. |
| 5 | **More WS candle resolutions** (5/30/240) | Low: REST already covers this for the filter | Very low (env `NOBITEX_WS_CANDLE_RESOLUTIONS`) | Low, once the WS resolutions are verified in the docs. An invalid channel fails silently (see E(c)). | Only for the live chart. |
| 6 | **Ticker/stats channel** | Low: REST `/market/stats` is enough at a 1-minute cadence | Low | Low | **Existence unverified.** |

**Honest caveat.** BTCIRT is a shallow, retail-heavy, rial-quoted market. Its price also follows the USDT/IRT rate and therefore FX news. Microstructure signals (book imbalance, per-minute order flow) are likely to be dominated by noise and by a few large participants. The most robust inputs for a regime filter here are slower ones: volatility (ATR%), multi-hour trend slope and volume regime from OHLCV. All of them can be backtested from data we already fetch.

---

### Open items needing the docs
1. Full list of public and private channel names, with payload schemas and units.
2. Whether a public trades channel exists, and whether each trade has a taker side, price unit, amount and ms timestamp.
3. REST `/v2/trades/{symbol}`: fields, units, how many trades it returns, and whether it supports a time range.
4. The WS candle resolution set (our code assumes it matches UDF), orderbook depth per publication, and the subscription and connection limits.
