# DISCOVERY_REPORT

پروژه: Grid Trading Bot برای نوبیتکس (Laravel)
شاخه: `claude/review-trading-bot-AFMpS`
تاریخ: 2026-05-19
وضعیت: گزارش فقط‌خواندنی — هیچ فایلی تغییر نکرده است.

---

## ۱) مسیرهای موازی ساخت سفارش مخالف

### الف) همه‌ی call-siteهای کلاس `TradingEngineService`

| # | فایل | خط | چه چیزی صدا زده شده |
|---|------|----|---------------------|
| 1 | `app/Filament/Resources/BotConfigResource/Pages/CreateBotConfig.php` | 8 | `use App\Services\TradingEngineService;` |
| 2 | `app/Filament/Resources/BotConfigResource/Pages/CreateBotConfig.php` | 437 | `$tradingEngine = app(TradingEngineService::class);` |
| 3 | `app/Filament/Resources/BotConfigResource/Pages/CreateBotConfig.php` | 438 | `$tradingEngine->initializeGrid($record)` |
| 4 | `app/Filament/Resources/BotConfigResource/Pages/EditBotConfig.php` | 6 | `use App\Services\TradingEngineService;` |
| 5 | `app/Filament/Resources/BotConfigResource/Pages/EditBotConfig.php` | 56 | `$tradingEngine = app(TradingEngineService::class);` |
| 6 | `app/Filament/Resources/BotConfigResource/Pages/EditBotConfig.php` | 57 | `$tradingEngine->getBotPerformanceReport($this->record)` ⚠ متد با این اسم در کلاس وجود ندارد (در کلاس `getPerformanceReport` است). در زمان اجرا BadMethodCall می‌دهد. |
| 7 | `app/Filament/Resources/BotConfigResource/Pages/ListBotConfigs.php` | 6 | `use App\Services\TradingEngineService;` |
| 8 | `app/Filament/Resources/BotConfigResource/Pages/ListBotConfigs.php` | 95 | `$tradingEngine = app(TradingEngineService::class);` |
| 9 | `app/Filament/Resources/BotConfigResource/Pages/ListBotConfigs.php` | 96 | `$tradingEngine->initializeGrid($bot)` |
| 10 | `app/Filament/Resources/BotConfigResource.php` | 7 | `use App\Services\TradingEngineService;` |
| 11 | `app/Filament/Resources/BotConfigResource.php` | 371 | `$tradingEngine = app(TradingEngineService::class);` |
| 12 | `app/Filament/Resources/BotConfigResource.php` | 372 | `$tradingEngine->initializeGrid($record)` |
| 13 | `app/Jobs/CheckTradesJob.php` | 9 | `use App\Services\TradingEngineService;` فقط import — هیچ‌جای کلاس استفاده نمی‌شود (dead import) |
| 14 | `routes/web.php` | 298–332 | روت تشخیصی `/test-trading-engine` که فقط `class_exists`, `app()`, و `get_class_methods()` صدا می‌زند؛ هیچ متد تجاری اجرا نمی‌شود. |
| 15 | `app/Contracts/TradingEngine.php` | 18 | اینترفیس `initializeGrid` تعریف کرده ولی `TradingEngineService` آن را پیاده‌سازی نکرده است (`class TradingEngineService` بدون `implements TradingEngine`). |

### ب) وضعیت متدهای داخلی

| متد | دسترسی | محل تعریف | از خارج کلاس صدا زده می‌شود؟ |
|-----|--------|-----------|------------------------------|
| `manageGridTrading` | public | TradingEngineService:168 | **خیر — dead code**. هیچ Controller/Job/Command/Filament-page آن را صدا نمی‌زند. |
| `createReplacementOrder` | private | TradingEngineService:505 | فقط از داخل `checkAndProcessOrders` (که خود از `manageGridTrading` صدا زده می‌شود). چون `manageGridTrading` dead است → **عملاً dead code**. |
| `processOrderStatusUpdate` | private | TradingEngineService:360 | همان زنجیره — **عملاً dead code**. |
| `checkForCompleteTrade` | private | TradingEngineService:413 | همان زنجیره — **عملاً dead code**. |
| `initializeGrid` | public | TradingEngineService:52 | بله — از سه Filament page (Create/List/Resource). **live code**. |
| `stopGrid` | public | TradingEngineService:249 | فقط داخلی (executeEmergencyStop, handleCriticalError) و آن‌ها از manageGridTrading → عملاً dead. |
| `getBotStatus`, `getPerformanceReport`, `forceRebalance`, `systemHealthCheck` | public | TradingEngineService | هیچ‌جا صدا زده نمی‌شود (به جز فراخوانی اشتباه `getBotPerformanceReport` در EditBotConfig:57 که نام را غلط می‌نویسد). |

**نتیجه:** کلِ زنجیره‌ی «پایش وضعیت سفارش → ساخت سفارش جایگزین» در `TradingEngineService` (manageGridTrading / processOrderStatusUpdate / checkForCompleteTrade / createReplacementOrder) از هیچ‌جا فراخوانی نمی‌شود. مسیر زنده‌ی فعلی برای ساخت سفارش مخالف فقط `CheckTradesJob::createPairOrder` است.

### ج) مقایسه `TradingEngineService::createReplacementOrder` با `CheckTradesJob::createPairOrder`

| ویژگی | `TradingEngineService::createReplacementOrder` (lines 505–589) | `CheckTradesJob::createPairOrder` (lines 441–553) |
|-------|----------------------------------------------------------------|----------------------------------------------------|
| فراخوانی واقعی؟ | خیر (dead) | بله — مسیر زنده‌ی production |
| محاسبه قیمت | `calculateReplacementPrice()` با گارد `deviation > 0.1` که `null` برمی‌گرداند | inline: `price * (1 ± spacing)` بدون هیچ گارد انحراف |
| نوع/جهت | از `$filledOrder->type` همان طرف را تکرار می‌کند (`type: $filledOrder->type`) — یعنی پس از پرشدن یک buy، یک buy دیگر می‌سازد (احتمالاً باگ منطقی) | جهت را برعکس می‌کند (`$filledOrder->type === 'buy' ? 'sell' : 'buy'`) — رفتار درست grid |
| Simulation | بله: شاخه `$botConfig->simulation` → fake `SIM-...` order_id | ندارد — همیشه real call به `placeOrder()` |
| متد API | `nobitexService->createOrder(CreateOrderDto)` (named-args DTO) | `nobitexService->placeOrder($symbol, $side, $price, $amount)` (positional) |
| ساخت GridOrder | با `parent_order_id` + `priority` + `level` | با `paired_order_id` (بدون priority/level) |
| Idempotency / clientRef | استفاده نمی‌کند (DTO بدون `clientRef`) | استفاده نمی‌کند |
| Transaction | ندارد | `DB::beginTransaction` + `commit`/`rollBack` |
| Round قیمت | `round($newPrice, 0)` | `(int) round($newPrice)` |
| لاگ‌گذاری | حداقلی (`Log::info`) | غنی (`Log::channel('trading')` + `BotActivityLogger`) |
| به‌روزرسانی متقابل sides | ندارد | `filledOrder->update(['paired_order_id' => $newOrder->id])` |
| Dedup-check قبل از API call | ندارد | ندارد |

**یافته‌ی مهم — احتمال باگ منطقی در createReplacementOrder:**
خط 573 `'type' => $filledOrder->type` به جای جهت مخالف — اگر هیچ‌گاه با مسیر زنده‌ای فراخوانی شود، چرخه grid را می‌شکند.

**نتیجه‌گیری در یک خط:** عملاً مسیر موازی دومی فعال نیست (فقط `CheckTradesJob::createPairOrder` کار می‌کند)، ولی منطق dead در `TradingEngineService` یک باگ نهفته دارد و باید قبل از refactor یا حذف شود یا اصلاح؛ ابقای آن «امن نیست» چون هر زمان کسی `manageGridTrading` را وصل کند، سفارش هم‌جهت ساخته خواهد شد.

---

## ۲) ناهماهنگی فیلد `simulation`

### الف) همه‌ی استفاده‌های نام‌ها

| نام | فایل | خط | متعلق به | مقدار default |
|-----|------|----|----------|---------------|
| `simulation` | `app/Models/BotConfig.php` | 28 (fillable), 62 (cast→boolean), 159 (`scopeSimulation`), 371 (`creating` event default `true`), 387 (rule) | ستون مدل `BotConfig` | `true` (هم در migration و هم در `creating` model event) |
| `simulation` | `database/migrations/2025_08_21_000001_update_bot_configs_for_new_bot_model.php` | 35–37, 80, 90 | جدول `bot_configs`, نوع `boolean`, ایندکس شده | `default(true)` |
| `simulation` | `app/Models/GridRun.php` | 26 (fillable), 41 (cast→boolean) | ستون مدل `GridRun` (جدول جدا) | بک‌فیل `true` (در GridRunRecorder:36) |
| `simulation` | `app/Filament/Resources/BotConfigResource.php` | 71 | Toggle UI روی فیلد `simulation` مدل BotConfig | — |
| `simulation` | `app/Filament/Resources/GridRunResource.php` | 57, 85 | ستون/فیلتر روی GridRun | — |
| `simulation` | `app/Services/TradingEngineService.php` | 515, 805, 850, 870, 909, 1042 | `$botConfig->simulation` ← هماهنگ با مدل ✅ | — |
| `simulation` (پارامتر متد) | `app/Services/GridOrderExecutor.php` | 35, 52, 79, 130, 184, 187 | پارامتر `bool $simulation` در `apply($diff, bool $simulation = true)` | default متد = `true` |
| `simulation` (در summary) | `app/Console/Commands/GridRunOnce.php` | 38, 60, 107 | پر کردن GridRun.simulation | از فلگ `--live` گرفته می‌شود |
| **`is_simulation`** ⚠ | `app/Jobs/AdjustGridJob.php` | 90 | `$simulate = (bool)($bot->is_simulation ?? false);` — این فیلد روی `BotConfig` **وجود ندارد** | همیشه `false` می‌شود → بات همیشه «live» اجرا می‌شود! |
| `simulation_mode` (config) | `config/trading.php` | 11 | env `TRADING_SIMULATION_MODE` | `false` |
| `trading.grid.simulation` (config) | `config/trading.php` | 118; `NobitexService.php` 833, 846 | فلگ کلی config | `false` |
| `simulationMode` (UI prop) | `app/Filament/Pages/ConnectionTest.php` | 34, 38–39, 94, 178, 201, 213, 241, 265, 351 | حالت شبیه‌سازی برای صفحه تست اتصال | از `config('trading.simulation_mode', true)` |
| `simulation_days`, `simulationResults` | `app/Livewire/GridCalculatorAdvanced.php`, `app/Filament/Pages/GridCalculator.php` | متعدد | شبیه‌سازی backtest — **بی‌ربط با dry-run معامله** | — |

### ب) Migration فیلد `simulation` روی `bot_configs`

- فایل: `database/migrations/2025_08_21_000001_update_bot_configs_for_new_bot_model.php` خطوط 35–37
- نام ستون: **`simulation`** (نه `is_simulation`، نه `dry_run`)
- نوع: `boolean` با `default(true)`
- ایندکس: `index('simulation')` و ایندکس ترکیبی `['is_active','simulation','symbol']`

### ج) `GridOrderExecutor::apply()` پارامتر `simulation` را از کجا می‌گیرد؟

- امضای متد (`GridOrderExecutor.php:52`): `apply(array $diff, bool $simulation = true)` — default = **true** (یعنی اگر صدازننده پاس ندهد، dry-run است).
- تنها صدازننده‌ی واقعی در پروژه: `AdjustGridJob.php:160` و `AdjustGridJob.php:162` که مقدار را از `$simulate = (bool)($bot->is_simulation ?? false)` می‌گیرند.
- **ناهماهنگی بحرانی:** مدل `BotConfig` فیلدی به نام `is_simulation` ندارد (نام درست `simulation` است). در نتیجه:
  - `$bot->is_simulation` همیشه `null` است
  - `?? false` آن را به `false` تبدیل می‌کند
  - یعنی **AdjustGridJob همیشه با `simulation=false` (live) اجرا می‌شود**، حتی اگر کاربر در UI تیک «simulation» را روشن گذاشته باشد.
- در مقابل، `TradingEngineService` همه‌جا از `$botConfig->simulation` استفاده می‌کند که با ستون migration و مدل هماهنگ است.

**نتیجه‌گیری در یک خط:** نامِ فیلد در `AdjustGridJob.php:90` غلط است (`is_simulation` به‌جای `simulation`) و این باعث می‌شود کاربر فکر کند dry-run است در حالی که real order ثبت می‌شود — **امن نیست، باید فوراً اصلاح شود**.

---

## ۳) وضعیت فعلی idempotency

### الف) فیلد client_order_id روی `grid_orders`؟

- جدول `grid_orders` در `database/migrations/2025_07_24_215103_create_grid_orders_table.php`: ستون‌ها = `id, bot_config_id, price, amount, type, status, nobitex_order_id, timestamps`. **هیچ ستون `client_order_id`, `idempotency_key`, یا `client_ref` وجود ندارد.**
- migrations بعدی روی `grid_orders` فقط اضافه کرده‌اند: `paired_order_id` (2025_07_26), `filled_at` (2025_10_21), fix `price` (2025_11_07). هیچ‌کدام client_order_id اضافه نمی‌کنند.
- ⚠ یافته‌ی ناهماهنگ: `app/Models/GridOrder.php:13` در آرایه `$fillable` نام‌های `'run_id','client_order_id','exchange_order_id','side', ...` آمده — اما این ستون‌ها در migrations جدول `grid_orders` ساخته نشده‌اند. به نظر می‌رسد مدل GridOrder از یک نسخه‌ی متفاوت پروژه کپی شده و با schema فعلی هم‌خوان نیست.
- جدول جداگانه‌ی `grid_run_orders` (`database/migrations/2025_08_20_131529_create_grid_run_orders_table.php:18`) **دارای ستون** `client_order_id` است، اما این جدول صرفاً برای مسیر `GridRun`/`GridRunRecorder`/`GridRunOnce` استفاده می‌شود و به جریان production `CheckTradesJob` و `AdjustGridJob` ربط مستقیم ندارد.

### ب) Dedup-check قبل از createOrder

- `GridOrderExecutor::apply()` (خطوط 100–176): قبل از `$this->svc->createOrder($dto)` **هیچ dedup-check** انجام نمی‌شود. تنها بررسی‌ها: `notional >= $minIrt` و `roundToTick`. هیچ نگاهی به DB یا OrderRegistry برای جلوگیری از سفارش تکراری نمی‌اندازد.
- `CheckTradesJob::createPairOrder` (خطوط 441–553): قبل از `placeOrder()` **هیچ dedup-check** نیست. (هرچند منطق `whereNull('paired_order_id')` در `processBot:91` ضامن می‌شود یک filled-order دو بار pair-order نسازد — این یک محافظت غیرمستقیم در سطح حلقه است، نه dedup قبل از API call.)
- `TradingEngineService::createReplacementOrder` (خطوط 505–589) و `placeGridOrders` (خطوط 842–1024): **هیچ dedup-check قبل از `createOrder` ندارند**.

### ج) TODOهای `GridOrderExecutor.php` درباره clientOrderId — کپیِ عینی

```
// TODO: Add applyForBot($botId, $diff, $simulation) method
// This should pass bot_id to order creation for proper scoping
//
// TODO: Add clientOrderId for idempotency:
// $clientOrderId = "grid:{$botId}:{$symbol}:{$side}:{$price}";
//
// TODO: Add deduplication check before creating order:
// if (Order::where('bot_config_id', $botId)
//     ->where('symbol', $symbol)
//     ->where('side', $side)
//     ->where('price', $price)
//     ->where('status', 'placed')
//     ->exists()) {
//     return; // Skip duplicate
// }
```
(خطوط 37–51 از `app/Services/GridOrderExecutor.php`)

ضمناً همان فایل (خط 142) یک `$clientRef = $this->buildClientRef($symbol, $side, $price)` می‌سازد و در `CreateOrderDto` پاس می‌دهد — اما (الف) شامل `time()` است (خط 230: `sprintf('grid:%s:%s:%d:%d', ..., time(), $price)`) پس بین فراخوانی‌های پشت‌سرهم هم unique است → **برای idempotency سمت کلاینت بی‌فایده است**؛ (ب) هرگز در DB ذخیره نمی‌شود.

**نتیجه‌گیری در یک خط:** Idempotency واقعی وجود ندارد — نه ستون DB، نه dedup-check، و clientRef موجود به دلیل داشتن `time()` در ساختار، در عمل برای جلوگیری از تکراری کار نمی‌کند؛ **امن نیست، نیاز به تغییر دارد**.

---

## ۴) وضعیت فعلی locking

### الف) `CheckTradesJob`

- داخل کلاس `CheckTradesJob.php`: **هیچ** `Cache::lock`, `GET_LOCK`, یا `WithoutOverlapping` interface وجود ندارد. کلاس فقط `ShouldQueue` را implement می‌کند (`$tries = 3`, `$timeout = 120`, `$backoff = [2,4,8]`).
- در سطح schedule (`routes/console.php:17–20`): `Schedule::job(new CheckTradesJob())->everyFiveMinutes()->withoutOverlapping()->name('check-trades')`.
- `withoutOverlapping()` بدون مدت → پیش‌فرض 24 ساعت expire. این لاک از طریق cache store انجام می‌شود؛ اگر دو instance روی همان cache database/redis مشترک باشند، اتمی است. اگر cache=file یا cache=array، فقط محلی است.
- توضیح کامنت خط 21: `// onOneServer() removed - not needed for single server setup` — یعنی روی single-server طراحی شده و onOneServer ندارد.

### ب) `AdjustGridJob`

- داخل کلاس (`AdjustGridJob.php`):
  - **Global lock** (خط 33): `DB::select("SELECT GET_LOCK(?, 1)", ['grid:adjust:global'])` با timeout=1s. MySQL `GET_LOCK` لاک سراسری روی همان MySQL server است → در صورت داشتن دو instance همزمان روی همان DB، اتمیک.
  - **Per-bot lock** (خط 79): `GET_LOCK('grid:adjust:bot:{$bot->id}', 1)`.
  - Release در `finally` block‌ها (خطوط 185, 195).
- سطح schedule (`routes/console.php:24–29`): `->everyTenMinutes()->withoutOverlapping(20)->onOneServer()`. هم withoutOverlapping(20m) و هم `onOneServer()` (نیازمند CACHE_STORE=database یا redis).

### ج) `ReadMarketStatsJob`

- داخل کلاس: هیچ lock مستقل ندارد.
- سطح schedule (`routes/console.php:37–43`): به ازای هر symbol `everyMinute()->withoutOverlapping(2)`.

### د) دو instance همزمان cron — آیا محافظت وجود دارد؟

| Job | محافظت در صورت همزمانی دو cron | نقطه ضعف |
|-----|-------------------------------|----------|
| `CheckTradesJob` | فقط `withoutOverlapping()` (cache-based, اتمی فقط با redis/database). **بدون** `onOneServer()` و **بدون** lock داخلی. | اگر cache=file و دو سرور وجود داشته باشد، هر دو همزمان می‌توانند trigger شوند → سفارش‌های تکراری ممکن. هیچ guard داخل handle نیست. |
| `AdjustGridJob` | سه لایه: `withoutOverlapping(20)` + `onOneServer()` + MySQL `GET_LOCK` داخلی. | امن — حتی اگر دو cron همزمان trigger کنند، GET_LOCK سراسری مانع می‌شود. |
| `ReadMarketStatsJob` | فقط `withoutOverlapping(2)`. عملیات read-only، خطر مالی ندارد. | خطر بی‌اهمیت. |

**نتیجه‌گیری در یک خط:** `AdjustGridJob` محافظت کافی دارد ولی `CheckTradesJob` (که اتفاقاً سفارش مالی ثبت می‌کند) فقط به `withoutOverlapping` cache-based متکی است و در صورت دو-instance یا cache=file ناامن است — **نیاز به تغییر دارد** (افزودن `onOneServer()` و/یا `GET_LOCK` داخلی).

---

## خلاصه‌ی کلی

| موضوع | وضعیت |
|------|-------|
| مسیر موازی ساخت سفارش | TradingEngineService dead است ولی باگ نهفته دارد → **پیش از refactor بررسی/حذف شود** |
| ناهماهنگی simulation | `AdjustGridJob` نام غلط `is_simulation` می‌خواند → **باگ بحرانی، اصلاح فوری** |
| Idempotency | هیچ مکانیزم واقعی وجود ندارد → **نیاز به اضافه‌کردن client_order_id + dedup-check** |
| Locking | `AdjustGridJob` امن، `CheckTradesJob` ضعیف → **`onOneServer()` یا GET_LOCK داخلی اضافه شود** |
