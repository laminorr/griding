# DISCOVERY_REPORT

پروژه: Grid Trading Bot برای نوبیتکس (Laravel)
تاریخ گزارش اولیه: 2026-05-19
تاریخ به‌روزرسانی: 2026-05-20
وضعیت: گزارش به‌روز شده پس از اعمال اصلاحات پرامپت A و پرامپت B.

> این فایل در ابتدا یک گزارش discovery فقط‌خواندنی بود که وضعیت پروژه را قبل از هر اصلاحی توصیف می‌کرد. در نسخه‌ی فعلی، هر بخش با جعبه‌ی «UPDATE» وضعیت پس از اصلاحات را نشان می‌دهد.

---

## خلاصه‌ی وضعیت فعلی (به‌روز شده 2026-05-20)

| موضوع | وضعیت اولیه | اصلاح‌شده در | وضعیت فعلی |
|------|--------------|----------------|-------------|
| مسیر موازی ساخت سفارش (TradingEngineService dead code با باگ نهفته) | باگ بحرانی | پرامپت A — PR #84 | ✅ حل شد |
| ناهماهنگی فیلد `is_simulation` در AdjustGridJob | باگ بحرانی | پرامپت A — PR #84 | ✅ حل شد |
| باگ `getBotPerformanceReport` در EditBotConfig | باگ runtime | پرامپت A — PR #84 | ✅ حل شد |
| Idempotency (نبود client_order_id و dedup-check) | ریسک بحرانی | پرامپت B — PR #85 | ✅ حل شد |
| `buildClientRef` بی‌فایده با `time()` | بی‌اثر | پرامپت B — PR #85 | ✅ حذف شد |
| Locking در CheckTradesJob | ریسک متوسط در صورت multi-server | بحث در پرامپت B | ⚠️ تصمیم آگاهانه: تک‌سرور → فقط `withoutOverlapping` + unique index در DB کافی است |
| Schema ناهماهنگ GridOrder (fillable با ستون‌های ناموجود) | کیفیت کد | پرامپت B — PR #85 | ✅ بخشی حل شد (`side`, `run_id` حذف؛ سایر فیلدهای مشکوک listed) |

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

### ب) وضعیت متدهای داخلی (وضعیت اولیه)

| متد | دسترسی | محل تعریف | از خارج کلاس صدا زده می‌شود؟ |
|-----|--------|-----------|------------------------------|
| `manageGridTrading` | public | TradingEngineService:168 | **خیر — dead code**. هیچ Controller/Job/Command/Filament-page آن را صدا نمی‌زند. |
| `createReplacementOrder` | private | TradingEngineService:505 | فقط از داخل `checkAndProcessOrders` (که خود از `manageGridTrading` صدا زده می‌شود). چون `manageGridTrading` dead است → **عملاً dead code**. |
| `processOrderStatusUpdate` | private | TradingEngineService:360 | همان زنجیره — **عملاً dead code**. |
| `checkForCompleteTrade` | private | TradingEngineService:413 | همان زنجیره — **عملاً dead code**. |
| `initializeGrid` | public | TradingEngineService:52 | بله — از سه Filament page (Create/List/Resource). **live code**. |
| `stopGrid` | public | TradingEngineService:249 | فقط داخلی (executeEmergencyStop, handleCriticalError) و آن‌ها از manageGridTrading → عملاً dead. |
| `getBotStatus`, `getPerformanceReport`, `forceRebalance`, `systemHealthCheck` | public | TradingEngineService | هیچ‌جا صدا زده نمی‌شود (به جز فراخوانی اشتباه `getBotPerformanceReport` در EditBotConfig:57 که نام را غلط می‌نویسد). |

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

**نتیجه‌گیری در یک خط (نسخه‌ی اولیه):** عملاً مسیر موازی دومی فعال نیست (فقط `CheckTradesJob::createPairOrder` کار می‌کند)، ولی منطق dead در `TradingEngineService` یک باگ نهفته دارد و باید قبل از refactor یا حذف شود یا اصلاح؛ ابقای آن «امن نیست» چون هر زمان کسی `manageGridTrading` را وصل کند، سفارش هم‌جهت ساخته خواهد شد.

> **✅ UPDATE 2026-05-20 (پرامپت A — PR #84):**
> کل زنجیره‌ی dead code از `TradingEngineService` حذف شد. ۱۰۸۴ خط کد پاک شد. متدهای حذف‌شده:
> - public: `manageGridTrading`, `stopGrid`, `getBotStatus`, `getPerformanceReport`, `forceRebalance`, `systemHealthCheck`
> - private/protected: `checkAndProcessOrders`, `processOrderStatusUpdate`, `checkForCompleteTrade`, `findPairOrder`, `createCompletedTrade`, `createReplacementOrder`, `performRiskManagementChecks`, `executeEmergencyStop`, `checkRebalanceNeeds`, `executeRebalance`, `cancelAllActiveOrders`, `calculateTradeFee`, `calculateReplacementPrice`, `calculateCurrentDrawdown`, `calculatePriceDeviation`, `performHealthCheck`, `handleCriticalError`, `updatePerformanceMetrics`, `generatePerformanceSummary`, `calculateWinRate`
> - 8 ثابت (constant) و 2 import که فقط توسط متدهای حذف‌شده استفاده می‌شدند.
>
> باگ نهفته در `createReplacementOrder` (خط 573) با حذف خود متد برطرف شد. کلاس الان فقط یک wrapper نازک حول `initializeGrid` است که از Filament call sites استفاده می‌شود. تأیید با تست دستی پنل ادمین: صفحه‌های Create و Edit ربات بدون خطا باز می‌شوند.

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

**نتیجه‌گیری در یک خط (نسخه‌ی اولیه):** نامِ فیلد در `AdjustGridJob.php:90` غلط است (`is_simulation` به‌جای `simulation`) و این باعث می‌شود کاربر فکر کند dry-run است در حالی که real order ثبت می‌شود — **امن نیست، باید فوراً اصلاح شود**.

> **✅ UPDATE 2026-05-20 (پرامپت A — PR #84):**
> خط 90 از `AdjustGridJob.php` اصلاح شد:
> ```diff
> -    $simulate = (bool)($bot->is_simulation ?? false);
> +    $simulate = (bool) $bot->simulation;
> ```
> `?? false` حذف شد چون migration ستون را با `default(true)` ساخته و `creating` event هم default را `true` می‌گذارد، پس null-fallback غیرضروری و گمراه‌کننده است. grep گسترده در کل پروژه (به جز این فایل) هیچ مورد دیگری از `is_simulation` پیدا نکرد. تأیید با تست دستی: ربات #2 با simulation=true ساخته شد و در پنل toggle درست نمایش داده می‌شود.
>
> **شواهد جانبی:** قبل از این اصلاح، در همان branch هاست تو `routes/console.php` با کامنت `// TEMPORARILY DISABLED - Still creating orders` کل `AdjustGridJob` غیرفعال شده بود. این تأیید عملی بود که باگ در محیط واقعی هم آسیب می‌زد و کاربر مجبور شده بود به‌صورت موقت job را خاموش کند. بعد از اصلاح، این workaround دیگر لازم نیست.

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

**نتیجه‌گیری در یک خط (نسخه‌ی اولیه):** Idempotency واقعی وجود ندارد — نه ستون DB، نه dedup-check، و clientRef موجود به دلیل داشتن `time()` در ساختار، در عمل برای جلوگیری از تکراری کار نمی‌کند؛ **امن نیست، نیاز به تغییر دارد**.

> **✅ UPDATE 2026-05-20 (پرامپت B — PR #85):**
>
> **(1) ستون‌های جدید روی `grid_orders` — migration اعمال شد:**
> - فایل: `database/migrations/2026_05_20_000001_add_idempotency_columns_to_grid_orders.php`
> - `client_order_id` — string nullable + **unique index** (`grid_orders_client_order_id_unique`)
> - `exchange_order_id` — string nullable + index معمولی
> - migration روی production اعمال شد در 2026-05-20 (`64.21ms DONE`).
>
> **(2) Schema ناهماهنگ مدل GridOrder بخشی حل شد:**
> - `side` و `run_id` از `$fillable` حذف شدند.
> - `client_order_id` و `exchange_order_id` در `$fillable` بودند، الان با ستون‌های واقعی DB هماهنگ شدند.
> - فیلدهای مشکوک باقی‌مانده (در `$fillable` ولی بدون ستون متناظر در migrations): `price_irt`, `matched`, `unmatched`, `raw_json`. Eloquent این‌ها را در writes silently drop می‌کند، پس بی‌اثرند ولی dead.
>
> **(3) buildClientOrderId deterministic ساخته شد:**
> - متد static روی `GridOrder` model.
> - فرمت: `grid:{botId}:{SYMBOL}:{side}:{priceIrt}:L{gridLevel}` (مثال: `grid:13:BTCIRT:buy:1390000000:L1`).
> - بدون `time()`، بدون random، خالصاً تابعی از ورودی‌ها. حداکثر ~50 کاراکتر.
>
> **(4) dedup-check قبل از createOrder در دو محل اضافه شد:**
> - `GridOrderExecutor::applyForBot()` (متد جدید جایگزین `apply()` برای مسیر AdjustGridJob): قبل از API call، با کوئری `client_order_id` چک می‌کند و اگر ردیف active (در status `pending`, `placed`, `filled`, `partially_filled`) وجود داشت، با لاگ `DEDUP_SKIP` رد می‌شود. علاوه بر این، ردیف `GridOrder` با `status=pending` و `client_order_id` **قبل** از API call ساخته می‌شود تا در صورت timeout/retry، تلاش بعدی همان ردیف را پیدا کرده و skip کند.
> - `CheckTradesJob::createPairOrder()`: همان dedup-check قبل از `placeOrder()`.
> - `TradingEngineService::placeGridOrders()`: dedup-check اضافه نشد (طبق تصمیم آگاهانه — این متد فقط از `initializeGrid` صدا زده می‌شود که با `cleanupExistingOrders` شروع می‌شود، پس dedup در آن redundant است و می‌تواند باگ‌ها را پنهان کند) ولی `client_order_id` به ردیف جدید نوشته می‌شود.
>
> **(5) `buildClientRef` با `time()` کاملاً حذف شد:**
> - grep تأیید کرد: zero matches در `app/`.
> - `client_ref` فقط در دو محل صحیح باقی ماند: `CreateOrderDto:87` و `NobitexService:697`. هر دو deterministic `client_order_id` را به نوبیتکس می‌فرستند.
>
> **(6) لایه‌ی دفاعی دوم: unique index در DB:**
> حتی اگر dedup-check در سطح کد به هر دلیلی شکست بخورد (race condition, bug, etc.)، unique index روی `client_order_id` در سطح دیتابیس INSERT دوم را با خطا رد می‌کند. این یعنی سفارش تکراری در DB غیرممکن است.

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

**نتیجه‌گیری در یک خط (نسخه‌ی اولیه):** `AdjustGridJob` محافظت کافی دارد ولی `CheckTradesJob` (که اتفاقاً سفارش مالی ثبت می‌کند) فقط به `withoutOverlapping` cache-based متکی است و در صورت دو-instance یا cache=file ناامن است — **نیاز به تغییر دارد** (افزودن `onOneServer()` و/یا `GET_LOCK` داخلی).

> **⚠️ UPDATE 2026-05-20 (تصمیم آگاهانه — هیچ تغییر کدی اعمال نشد):**
> پس از تأیید کاربر مبنی بر اینکه پروژه روی **یک سرور** اجرا می‌شود و در آینده‌ی نزدیک scale نمی‌شود، تصمیم گرفته شد فعلاً به همان `withoutOverlapping()` cache-based اکتفا شود.
>
> **چرا این تصمیم الان امن‌تر از قبل است؟**
> پرامپت B دو لایه‌ی دفاعی جدید برای مسئله‌ی duplicate-order اضافه کرد که قبلاً وجود نداشت:
> 1. **Dedup-check در `CheckTradesJob::createPairOrder()`** — قبل از هر `placeOrder` کوئری روی `client_order_id` می‌زند.
> 2. **Unique index در DB روی `client_order_id`** — حتی اگر race condition در سطح کد رخ دهد، INSERT دوم با خطا رد می‌شود.
>
> یعنی حتی اگر فردا (با وجود اینکه setup تک‌سرور است) دو instance همزمان CheckTradesJob به‌طور غیرمنتظره trigger شوند، **DB-level constraint جلوی duplicate را می‌گیرد**. این یک trade-off آگاهانه است: ساده‌ترین تنظیم scheduler به اضافه‌ی محافظت چندلایه در سطح dedup. اگر روزی پروژه multi-server شد، اضافه کردن `onOneServer()` یا `GET_LOCK` داخلی فقط چند خط است.

---

## ۵) باگ runtime در `EditBotConfig`

> این بخش در گزارش اولیه به‌عنوان «یافته‌ی جانبی» ذکر شده بود (در سطر 21 جدول call-siteها). برای روشن‌تر بودن، در نسخه‌ی به‌روز جداگانه نوشته می‌شود.

**وضعیت اولیه:**
- در `app/Filament/Resources/BotConfigResource/Pages/EditBotConfig.php` خط 57، header action ای به نام `performance_report` بود که `$tradingEngine->getBotPerformanceReport($this->record)` را صدا می‌زد.
- اما متدی با این اسم در `TradingEngineService` وجود نداشت. متد مشابه (`getPerformanceReport` بدون `Bot`) بود.
- هر کلیک روی این دکمه → `BadMethodCallException` در runtime.
- علاوه بر این، view هایی که action قرار بود رندر کند (`filament.modals.bot-performance-detailed` و `filament.modals.error`) **در پروژه وجود نداشتند** — کل دایرکتوری `resources/views/filament/modals/` غایب بود. یعنی این action از روز اول هم نمی‌توانست کار کند.

> **✅ UPDATE 2026-05-20 (پرامپت A — PR #84):**
> طبق دستور پرامپت ("delete, not re-route") کل header action `performance_report` و import متناظر `TradingEngineService` از `EditBotConfig.php` حذف شد. این تصمیم با دستور دیگر پرامپت ("do not invent new methods to keep the old call alive") هم سازگار بود. تأیید عملی: در اسکرین‌شات تست دستی، صفحه‌ی Edit ربات بدون خطا باز شد و فقط دو دکمه‌ی `بررسی سلامت` و `تحلیل ریسک` در header باقی ماندند.

---

## ۶) موارد باقی‌مانده / کارهای آینده

این موارد در پرامپت‌های فعلی **عمداً** بسته نشدند یا نیاز به اقدام در آینده دارند:

1. **حذف کامل `apply()` legacy از `GridOrderExecutor`:** متد قدیمی `apply()` با dead `else` branch در `AdjustGridJob.php:162` هنوز در کد است. الان unreachable است (`method_exists($exec, 'applyForBot')` همیشه true است)، ولی dead code محسوب می‌شود. می‌توان در یک cleanup بعدی حذف شود.

2. **فیلدهای مشکوک در `GridOrder::$fillable`:** `price_irt`, `matched`, `unmatched`, `raw_json` در fillable هستند ولی ستون متناظر در migrations جدول `grid_orders` ندارند. Eloquent این‌ها را در writes silently drop می‌کند. در پرامپت B عمداً دست نخوردند (rule: «conservative — only remove entries you are confident are unused»). برای تصمیم در آینده: یا migration برای ایجادشان، یا حذف از fillable.

3. **پر کردن `exchange_order_id`:** ستون اضافه شد ولی هیچ مسیر کدی هنوز آن را نمی‌نویسد. فعلاً forward-looking است (برای پشتیبانی چند صرافی در آینده).

4. **سود `CompletedTrade` با کارمزد واقعی:** بحث در گزارش اولیه (بخش ۸ نسخه‌ی قدیم) که سود فعلی `(sellPrice - buyPrice) * amount` با کارمزد ثابت `0.0035` hard-coded محاسبه می‌شود؛ این باید با نرخ واقعی fee صرافی و amount واقعی fill (نه amount درخواست) محاسبه شود. نیاز به یک پرامپت جداگانه دارد.

5. **مدیریت partial fill و timeout:** سناریوهای A11 و A12 از ضمیمه‌ی تفصیلی A در `Saves Description` هنوز سناریوی رسمی ندارند. الان با وجود `client_order_id` و dedup-check، خطر duplicate در timeout بسیار کمتر شده، ولی منطق partial fill (وقتی فقط بخشی از مقدار پر می‌شود) هنوز در `CheckTradesJob` فقط FILLED کامل را پردازش می‌کند.

6. **Locking در `CheckTradesJob` در صورت multi-server شدن آینده:** اگر روزی تصمیم گرفته شد پروژه روی چند سرور اجرا شود، یا `onOneServer()` به scheduler اضافه شود یا `GET_LOCK` داخلی مشابه `AdjustGridJob` اضافه شود.

---

## ۷) چک‌لیست به‌روز شده

- [x] آیا در پنل، simulation دقیقاً روشن/خاموش می‌شود؟ → **بله** (پرامپت A با اصلاح `is_simulation`)
- [x] آیا همه فایل‌ها از یک نام فیلد simulation استفاده می‌کنند؟ → **بله** (تنها مورد ناهماهنگ `is_simulation` اصلاح شد)
- [x] آیا TradingEngineService واقعاً جایی صدا زده می‌شود؟ → **فقط `initializeGrid` از سه Filament page** (پرامپت A بقیه را پاک کرد)
- [ ] آیا با grid=4 همیشه ۲ buy و ۲ sell ساخته می‌شود؟ → **نیاز به تست عملی T1**
- [ ] بعد از buy filled، فقط یک sell جدید ساخته می‌شود؟ → **dedup-check + unique index الان این را تضمین می‌کند، ولی T2 لازم است**
- [ ] بعد از sell filled، فقط یک buy جدید ساخته می‌شود؟ → **همان**، T3 لازم
- [x] اگر CheckTradesJob دو بار پشت سر هم اجرا شود، duplicate نمی‌سازد؟ → **بله** (dedup-check + unique index)
- [x] اگر API timeout بدهد، ربات دوباره سفارش نمی‌سازد؟ → **بله** (`pending` row با `client_order_id` قبل از API call ساخته می‌شود؛ retry آن را پیدا و skip می‌کند)
- [ ] اگر قیمت شدیداً از گرید خارج شود، pairedها چه می‌شوند؟ → **هنوز تصمیم محصولی روشن نشده**
- [ ] سود CompletedTrade با کارمزد واقعی هم‌خوان است؟ → **هنوز نه** (مورد ۴ از موارد باقی‌مانده)

---

## ۸) جمع‌بندی مدیریتی (به‌روز شده)

ربات از نظر ایده‌ی اصلی، چرخه‌ی Grid Trading را داشت و دارد:
- گرید اولیه می‌سازد.
- سفارش خرید که پر شود، فروش بالاتر می‌گذارد.
- سفارش فروش که پر شود، خرید پایین‌تر می‌گذارد.
- اگر دو سمت مناسب پیدا کند، CompletedTrade می‌سازد.

**در گزارش اولیه** پنج نگرانی اصلی برای راه‌اندازی با سرمایه‌ی واقعی شناسایی شده بود:
1. مسیر TradingEngineService باید تعیین تکلیف می‌شد. → ✅ حل شد
2. simulation باید یکپارچه و بدون ابهام می‌شد. → ✅ حل شد
3. duplicate order باید با تست idempotency کنترل می‌شد. → ✅ حل شد (client_order_id + dedup + unique index)
4. partial fill و timeout باید سناریوی رسمی داشتند. → ⚠️ timeout حل شده، partial fill هنوز نه
5. سود CompletedTrade باید با fee/slippage واقعی مقایسه می‌شد. → ⚠️ هنوز نه

**مرحله‌ی بعد (پیشنهاد):** اجرای سناریوهای تست T0 تا T7 از برنامه‌ی تست اولیه روی محیط واقعی، با ربات Grid Bot #2 (با simulation=true). اگر T0 تا T7 پاس شدند، می‌توان به test live محدود (T8) با سرمایه‌ی خیلی کم رفت.

پایان نسخه‌ی به‌روز شده.
