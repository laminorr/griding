# Grid Bot – Technical Documentation (Developer Onboarding)

## 1. High-Level Overview

### پروژه چیست؟
این سیستم یک **Grid Trading Bot** برای صرافی **Nobitex** است. Grid Trading یک استراتژی معاملاتی خودکار است که شبکه‌ای از سفارشات خرید و فروش را در فواصل قیمتی مشخص قرار می‌دهد تا از نوسانات بازار سود ببرد.

### مفهوم Grid Trading در این کد
- سیستم یک **قیمت مرکزی** (center_price) تعیین می‌کند
- سفارشات **خرید** در قیمت‌های پایین‌تر از مرکز قرار می‌گیرند
- سفارشات **فروش** در قیمت‌های بالاتر از مرکز قرار می‌گیرند
- وقتی قیمت پایین می‌آید → خرید انجام می‌شود → سفارش فروش جدید ساخته می‌شود
- وقتی قیمت بالا می‌رود → فروش انجام می‌شود → سفارش خرید جدید ساخته می‌شود
- این چرخه ادامه پیدا می‌کند و از هر نوسان سود کسب می‌شود

### نقش‌های اصلی سیستم

| کامپوننت | مسیر فایل | توضیح |
|----------|-----------|-------|
| **BotConfig** | `app/Models/BotConfig.php` | تنظیمات هر ربات (بودجه، تعداد لول‌ها، فاصله گرید) |
| **GridOrder** | `app/Models/GridOrder.php` | سفارش‌های شبکه (buy/sell، قیمت، وضعیت) |
| **CompletedTrade** | `app/Models/CompletedTrade.php` | معاملات تکمیل‌شده با محاسبه سود |
| **BotActivityLog** | `app/Models/BotActivityLog.php` | لاگ فعالیت‌ها برای debug و monitoring |
| **CheckTradesJob** | `app/Jobs/CheckTradesJob.php` | بررسی وضعیت سفارشات هر 5 دقیقه |
| **AdjustGridJob** | `app/Jobs/AdjustGridJob.php` | تنظیم مجدد گرید هر 10 دقیقه |
| **ReadMarketStatsJob** | `app/Jobs/ReadMarketStatsJob.php` | خواندن آمار بازار هر دقیقه |
| **TradingEngineService** | `app/Services/TradingEngineService.php` | موتور اصلی ترید و مدیریت گرید |
| **GridCalculatorService** | `app/Services/GridCalculatorService.php` | محاسبه سطوح گرید و سود مورد انتظار |
| **NobitexService** | `app/Services/NobitexService.php` | کلاینت REST برای API نوبیتکس |
| **BotActivityLogger** | `app/Services/BotActivityLogger.php` | ثبت لاگ فعالیت‌های ربات |

---

## 2. Project Structure

```
app/
├── Models/
│   ├── BotConfig.php          # تنظیمات ربات
│   ├── GridOrder.php          # سفارشات گرید
│   ├── CompletedTrade.php     # معاملات تکمیل‌شده
│   ├── BotActivityLog.php     # لاگ فعالیت
│   ├── GridRun.php            # اجرای گرید
│   ├── GridRunOrder.php       # سفارشات اجرا
│   └── GridEvent.php          # رویدادهای گرید
├── Jobs/
│   ├── CheckTradesJob.php     # بررسی سفارشات (هر 5 دقیقه)
│   ├── AdjustGridJob.php      # تنظیم گرید (هر 10 دقیقه)
│   └── ReadMarketStatsJob.php # آمار بازار (هر دقیقه)
├── Services/
│   ├── TradingEngineService.php    # موتور ترید
│   ├── GridCalculatorService.php   # محاسبات گرید
│   ├── NobitexService.php          # کلاینت API نوبیتکس
│   ├── BotActivityLogger.php       # لاگر فعالیت
│   ├── GridPlanner.php             # برنامه‌ریز گرید
│   ├── GridOrderSync.php           # همگام‌سازی سفارشات
│   ├── GridOrderExecutor.php       # اجرای سفارشات
│   └── MarketDataLayer.php         # لایه داده بازار
├── Enums/
│   ├── GridOrderStatus.php    # PENDING, ACTIVE, FILLED, CANCELED, ERROR
│   ├── OrderSide.php          # BUY, SELL
│   └── ExecutionType.php      # MARKET, LIMIT
├── DTOs/
│   ├── CreateOrderDto.php
│   ├── OrderStatusDto.php
│   └── ...
├── Filament/
│   └── Pages/
│       ├── BotMonitoring.php       # صفحه مانیتورینگ اصلی
│       ├── BotIntelDashboard.php   # داشبورد هوشمند
│       └── GridCalculator.php      # ماشین‌حساب گرید
database/
├── migrations/
│   ├── 2025_07_24_214742_create_bot_configs_table.php
│   ├── 2025_07_24_215103_create_grid_orders_table.php
│   ├── 2025_07_24_215225_create_completed_trades_table.php
│   ├── 2025_11_09_000001_create_bot_activity_logs_table.php
│   └── ...
routes/
└── console.php                # زمان‌بندی Jobs
```

---

## 3. Domain Model & Database

### 3.1 BotConfig
**جدول:** `bot_configs`

**ستون‌های مهم:**
| ستون | نوع | توضیح |
|------|-----|-------|
| `name` | string | نام ربات |
| `symbol` | string | جفت ارز (مثلاً BTCIRT) |
| `is_active` | boolean | فعال/غیرفعال |
| `total_capital` | decimal(20,0) | بودجه کل به ریال |
| `active_capital_percent` | decimal(5,2) | درصد سرمایه فعال |
| `grid_spacing` | decimal(5,2) | فاصله درصدی بین لول‌ها |
| `grid_levels` | integer | تعداد سطوح گرید |
| `center_price` | decimal(20,0) | قیمت مرکزی |
| `simulation` | boolean | حالت شبیه‌سازی |
| `mode` | string | buy/sell/both |

**روابط:**
- `hasMany(GridOrder::class)` - سفارشات گرید
- `hasMany(CompletedTrade::class)` - معاملات تکمیل‌شده
- `hasMany(GridRun::class)` - اجراهای گرید

**متدهای مهم:**
- `start()` / `stop()` - شروع/توقف ربات
- `getTotalProfitAttribute()` - سود کل
- `getWinRateAttribute()` - نرخ برد
- `needsRebalance()` - آیا نیاز به تعادل مجدد دارد؟

### 3.2 GridOrder
**جدول:** `grid_orders`

**ستون‌های مهم:**
| ستون | نوع | توضیح |
|------|-----|-------|
| `bot_config_id` | FK | ربات مربوطه |
| `price` | decimal(20,0) | قیمت به ریال |
| `amount` | decimal(20,8) | مقدار کریپتو |
| `type` | enum | buy/sell |
| `status` | enum | pending/placed/filled/cancelled |
| `nobitex_order_id` | string | ID سفارش در نوبیتکس |
| `paired_order_id` | FK | سفارش جفت‌شده |
| `filled_at` | datetime | زمان اجرا |

**معنی `paired_order_id`:**
- وقتی یک سفارش خرید fill می‌شود، سفارش فروش جدید ساخته می‌شود و `paired_order_id` هر دو به هم لینک می‌شود
- این برای ردیابی چرخه‌های کامل خرید→فروش استفاده می‌شود

**وضعیت‌های ممکن:**
- `pending`: آماده ارسال به صرافی
- `placed`: ثبت شده در صرافی، منتظر اجرا
- `filled`: کاملاً اجرا شده
- `cancelled`: لغو شده

### 3.3 CompletedTrade
**جدول:** `completed_trades`

**ستون‌های مهم:**
| ستون | نوع | توضیح |
|------|-----|-------|
| `buy_order_id` | FK | سفارش خرید |
| `sell_order_id` | FK | سفارش فروش |
| `buy_price` | decimal | قیمت خرید |
| `sell_price` | decimal | قیمت فروش |
| `amount` | decimal | مقدار |
| `profit` | decimal | سود خالص |
| `fee` | decimal | کارمزد |
| `gross_profit` | decimal | سود ناخالص |
| `net_profit` | decimal | سود خالص |
| `profit_percentage` | decimal | درصد سود |
| `execution_time_seconds` | int | مدت زمان اجرا |
| `market_conditions` | json | شرایط بازار |
| `trade_type` | string | grid/manual/stop_loss |

**Computed Attributes:**
- `profit_toman` - سود به تومان
- `volume_toman` - حجم به تومان
- `is_profitable` - آیا سودآور است
- `roi_percentage` - درصد بازدهی
- `execution_time_formatted` - زمان فرمت‌شده

**متد استاتیک مهم:**
```php
CompletedTrade::createFromOrders(GridOrder $buyOrder, GridOrder $sellOrder)
```
این متد:
1. سود ناخالص را محاسبه می‌کند: `(sellPrice - buyPrice) * amount`
2. کارمزد را حساب می‌کند
3. سود خالص را می‌گیرد
4. زمان اجرا را محاسبه می‌کند
5. شرایط بازار را از cache می‌خواند (`btc_price`, `btc_price_1h_ago`)
6. روند بازار را تشخیص می‌دهد (`detectMarketTrend()`)

### 3.4 BotActivityLog
**جدول:** `bot_activity_logs`

**ستون‌های مهم:**
| ستون | نوع | توضیح |
|------|-----|-------|
| `bot_config_id` | FK | ربات مربوطه |
| `action_type` | string | نوع عمل |
| `level` | string | INFO/SUCCESS/WARNING/ERROR |
| `message` | string | پیام فارسی |
| `details` | json | جزئیات |
| `api_request` | json | درخواست API |
| `api_response` | json | پاسخ API |
| `execution_time` | int | میلی‌ثانیه |

**انواع `action_type`:**
- `CHECK_TRADES_START` / `CHECK_TRADES_END`
- `API_CALL`
- `ORDERS_RECEIVED`
- `ORDER_PLACED` / `ORDER_FILLED`
- `ORDER_PAIRED`
- `TRADE_COMPLETED`
- `ERROR`

---

## 4. Trading Lifecycle & Grid Logic

### 4.1 راه‌اندازی ربات

1. **ایجاد BotConfig** با تنظیمات:
   - `symbol`: BTCIRT
   - `grid_levels`: تعداد سطوح (مثلاً 6)
   - `grid_spacing`: فاصله درصدی (مثلاً 2%)
   - `total_capital`: بودجه کل
   - `active_capital_percent`: درصد فعال

2. **فعال‌سازی** با `$bot->start()` یا `is_active = true`

### 4.2 ایجاد سفارشات (Grid Orders)

**سرویس مسئول:** `TradingEngineService::initializeGrid()`

**فلو:**
```
1. performPreflightChecks() - بررسی API key و اتصال
2. analyzeMarketForGrid() - تحلیل شرایط بازار
3. calculateOptimalCenterPrice() - محاسبه قیمت مرکز
4. GridCalculatorService::calculateGridLevels() - محاسبه سطوح
5. GridCalculatorService::calculateOrderSize() - محاسبه اندازه سفارش
6. cleanupExistingOrders() - پاکسازی سفارشات قدیمی
7. placeGridOrders() - ثبت سفارشات جدید
```

**لاجیک محاسبه سطوح (الگوریتم لگاریتمی):**
```php
// سطوح خرید (پایین‌تر از مرکز)
for ($i = 1; $i <= $halfLevels; $i++) {
    $price = $centerPrice * pow(1 - $spacingDecimal, $i);
}

// سطوح فروش (بالاتر از مرکز)
for ($i = 1; $i <= $halfLevels; $i++) {
    $price = $centerPrice * pow(1 + $spacingDecimal, $i);
}
```

### 4.3 دوره اجرای ربات (Jobs)

#### CheckTradesJob (هر 5 دقیقه)

```
CheckTradesJob::handle()
  → Load active bots (BotConfig::where('is_active', true))
  → For each bot:
      → processBot($bot)
          → Get active orders (status = 'placed')
          → checkOrdersStatus() - دریافت وضعیت از Nobitex API
          → For each order:
              → processOrderStatus()
              → If status == 'FILLED':
                  → handleFilledOrder()
                      → Update order status to 'filled'
                      → Set filled_at
                      → createCompletedTradeIfPaired()
                          → Find matching buy/sell order
                          → If found: recordCompletedTrade()
                              → CompletedTrade::createFromOrders()
                          → Update paired_order_id
          → Get filled orders without pair
          → For each: createPairOrder()
              → Calculate new price (± grid_spacing)
              → Place order on Nobitex
              → Create new GridOrder with paired_order_id
```

#### جفت‌شدن سفارشات

وقتی یک سفارش **sell** پر می‌شود:
```php
// پیدا کردن آخرین buy پر شده با قیمت کمتر
$buyOrder = GridOrder::where('type', 'buy')
    ->where('status', 'filled')
    ->where('price', '<', $sellOrder->price)
    ->whereNull('paired_order_id')
    ->orderBy('price', 'desc')
    ->first();

if ($buyOrder) {
    CompletedTrade::createFromOrders($buyOrder, $sellOrder);
    $buyOrder->update(['paired_order_id' => $sellOrder->id]);
    $sellOrder->update(['paired_order_id' => $buyOrder->id]);
}
```

### 4.4 محاسبه سود

```php
$grossProfit = ($sellPrice - $buyPrice) * $amount;
$feeRate = 0.0035; // 0.35%
$totalFee = (($buyPrice * $amount) + ($sellPrice * $amount)) * $feeRate;
$netProfit = $grossProfit - $totalFee;
$profitPercentage = ($grossProfit / ($buyPrice * $amount)) * 100;
$executionTime = $sellOrder->updated_at->diffInSeconds($buyOrder->created_at);
```

---

## 5. Background Jobs & Scheduling

### زمان‌بندی (routes/console.php)

| Job | فرکانس | توضیح |
|-----|--------|-------|
| `CheckTradesJob` | هر 5 دقیقه | بررسی وضعیت سفارشات |
| `AdjustGridJob` | هر 10 دقیقه | تنظیم مجدد گرید |
| `ReadMarketStatsJob` | هر دقیقه | آمار بازار (BTCIRT, ETHIRT, USDTIRT) |

### CheckTradesJob
**مسیر:** `app/Jobs/CheckTradesJob.php`

- **Tries:** 3
- **Timeout:** 120 ثانیه
- **Backoff:** [2, 4, 8] ثانیه

**وظایف:**
1. بارگذاری ربات‌های فعال
2. بررسی وضعیت سفارشات از API نوبیتکس
3. آپدیت سفارشات محلی
4. ایجاد CompletedTrade برای سفارشات جفت‌شده
5. ایجاد سفارش جدید در جهت مخالف

### AdjustGridJob
**مسیر:** `app/Jobs/AdjustGridJob.php`

**وظایف:**
1. گرفتن قفل سراسری (جلوگیری از اجرای همزمان)
2. برای هر ربات فعال:
   - بررسی symbol در whitelist
   - محاسبه plan جدید با GridPlanner
   - مقایسه با سفارشات موجود
   - اعمال تغییرات با GridOrderExecutor

### ReadMarketStatsJob
**مسیر:** `app/Jobs/ReadMarketStatsJob.php`

**وظایف:**
- خواندن order book از نوبیتکس
- لاگ کردن قیمت، spread، و آمار

---

## 6. Services & Integrations (Nobitex)

### NobitexService
**مسیر:** `app/Services/NobitexService.php`

**Endpointهای مهم:**

| متد | Endpoint | توضیح |
|-----|----------|-------|
| `getOrderBook()` | `/market/orderbook` | دریافت order book |
| `createOrder()` | `/market/orders/add` | ثبت سفارش |
| `cancelOrder()` | `/market/orders/update-status` | لغو سفارش |
| `getOrdersStatus()` | `/market/orders/status` | وضعیت سفارش |
| `getCurrentPrice()` | `/market/stats` | قیمت لحظه‌ای |
| `getBalances()` | `/users/wallets/list` | موجودی‌ها |
| `healthCheck()` | `/users/profile` | تست اتصال |

**تنظیمات HTTP:**
- Timeout: 8 ثانیه
- Retry: 3 بار با backoff
- Rate limit: soft limit per-route

### TradingEngineService
**مسیر:** `app/Services/TradingEngineService.php`

**متدهای اصلی:**
- `initializeGrid()` - راه‌اندازی کامل گرید
- `manageGridTrading()` - مدیریت معاملات (main loop)
- `stopGrid()` - توقف ایمن
- `forceRebalance()` - تعادل مجدد دستی
- `getBotStatus()` - وضعیت کامل ربات
- `getPerformanceReport()` - گزارش عملکرد

### GridCalculatorService
**مسیر:** `app/Services/GridCalculatorService.php`

**متدهای اصلی:**
- `calculateGridLevels()` - محاسبه سطوح گرید
- `calculateOrderSize()` - اندازه سفارش بهینه
- `calculateExpectedProfit()` - سود مورد انتظار
- `assessGridRisk()` - ارزیابی ریسک
- `getOptimalSettings()` - تنظیمات بهینه
- `quickMarketAnalysis()` - تحلیل سریع بازار

---

## 7. Monitoring & Dashboards (Filament)

### BotMonitoring
**مسیر:** `app/Filament/Pages/BotMonitoring.php`
**View:** `resources/views/filament/pages/bot-monitoring.blade.php`

**بخش‌های UI:**
1. **کارت‌های بالا:**
   - Active Orders count
   - Filled 24h
   - Completed Trades 24h
   - Profit 24h
   - Profit Change %

2. **لیست سفارشات فعال:**
   - ID, Type, Price, Amount, Status

3. **نمودار سود روزانه:**
   - 30 روز اخیر

4. **Timeline لاگ‌ها:**
   - چرخه‌های CHECK_TRADES
   - API calls
   - Errors

**متدهای مهم:**
- `getBotData()` - تمام داده‌های ربات
- `groupLogsToCycles()` - گروه‌بندی لاگ‌ها به چرخه
- `calculateSummaryStats()` - آمار خلاصه

**KPIهای مهم:**
- `completed_trades_24h` - معاملات 24h
- `profit_24h` - سود 24h
- `profit_change_24h` - تغییر سود نسبت به 24h قبل
- `avg_cycle_duration` - میانگین مدت چرخه
- `avg_api_latency` - میانگین تاخیر API
- `error_count_24h` - تعداد خطا 24h

---

## 8. Configuration & Env Requirements

### Environment Variables مهم

```bash
# Nobitex API
NOBITEX_API_KEY=your_api_key
NOBITEX_BASE_URL=https://apiv2.nobitex.ir
NOBITEX_USE_TESTNET=false
NOBITEX_HTTP_TIMEOUT=8.0
NOBITEX_RETRY_MAX_ATTEMPTS=3

# Grid Trading Defaults
GRID_DEFAULT_CAPITAL=100000000
GRID_DEFAULT_ACTIVE_PERCENT=30
GRID_DEFAULT_SPACING=1.5
GRID_DEFAULT_LEVELS=10
GRID_FEE_RATE=0.35

# Trading
TRADING_ALLOWED_SYMBOLS=BTCIRT
TRADING_MIN_ORDER_VALUE_IRT=3000000
TRADING_ENABLE_SCHEDULER=true
TRADING_SIMULATION_MODE=false

# Queue & Cache
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### نیازمندی‌های Queue
- `QUEUE_CONNECTION=database` برای job ها
- `CACHE_STORE=database` یا `redis` برای قفل‌ها

---

## 9. Error Handling, Logging & Activity Logs

### لاگ‌های سیستم

| Channel | فایل | توضیح |
|---------|------|-------|
| `laravel` | `storage/logs/laravel.log` | لاگ عمومی |
| `trading` | `storage/logs/trading.log` | لاگ ترید |
| `nobitex` | `storage/logs/nobitex.log` | لاگ API نوبیتکس |

### BotActivityLog

**سطوح (level):**
- `INFO` - اطلاعات عادی
- `SUCCESS` - موفقیت
- `WARNING` - هشدار
- `ERROR` - خطا

**انواع عمل (action_type):**
- `CHECK_TRADES_START` / `CHECK_TRADES_END`
- `API_CALL`
- `ORDERS_RECEIVED`
- `ORDER_PLACED` / `ORDER_FILLED` / `ORDER_PAIRED`
- `TRADE_COMPLETED`
- `ERROR`

### Debug کردن

1. **لاگ‌های فایل:**
   ```bash
   tail -f storage/logs/trading.log
   tail -f storage/logs/laravel.log
   ```

2. **Activity Logs در DB:**
   ```php
   BotActivityLog::where('bot_config_id', $botId)
       ->where('level', 'ERROR')
       ->latest()
       ->get();
   ```

3. **UI Timeline:**
   صفحه BotMonitoring → Activity Cycles

---

## 10. Extending the System (How to Add Features)

### اضافه کردن متریک جدید به CompletedTrade

1. **Migration:**
   ```bash
   php artisan make:migration add_new_metric_to_completed_trades
   ```
   ```php
   $table->decimal('new_metric', 10, 4)->nullable();
   ```

2. **Model:**
   - اضافه به `$fillable`
   - اضافه به `$casts`
   - ایجاد accessor اگر نیاز است

3. **createFromOrders():**
   محاسبه و ذخیره متریک جدید

4. **UI (اختیاری):**
   نمایش در BotMonitoring

### اضافه کردن KPI جدید به مانیتورینگ

1. **BotMonitoring.php:**
   در `getBotData()` متریک را محاسبه و اضافه کنید

2. **View:**
   در `bot-monitoring.blade.php` نمایش دهید

### پیاده‌سازی استراتژی جدید

1. **سرویس جدید:**
   ```php
   // app/Services/NewStrategyService.php
   class NewStrategyService {
       public function calculateLevels(...) { }
   }
   ```

2. **اضافه به BotConfig:**
   فیلد `strategy_type` یا `settings_json`

3. **تغییر TradingEngineService:**
   بر اساس strategy سرویس مناسب را صدا بزنید

4. **Job جدید (اختیاری):**
   اگر فلو متفاوت دارد

---

## 11. Glossary of Terms

| اصطلاح | توضیح |
|--------|-------|
| **BotConfig** | تنظیمات یک ربات شامل بودجه، تعداد لول‌ها، فاصله گرید |
| **GridOrder** | یک سفارش در شبکه گرید (خرید یا فروش) |
| **CompletedTrade** | یک چرخه کامل خرید→فروش که سود محاسبه شده |
| **Grid Level** | یک سطح قیمتی در شبکه گرید |
| **paired_order_id** | لینک بین دو سفارش جفت‌شده (buy↔sell) |
| **center_price** | قیمت مرکزی که سطوح گرید حولش ساخته می‌شوند |
| **grid_spacing** | فاصله درصدی بین سطوح (مثلاً 2%) |
| **grid_levels** | تعداد کل سطوح (باید زوج باشد: نصف buy، نصف sell) |
| **gross_profit** | سود ناخالص = (قیمت فروش - قیمت خرید) × مقدار |
| **net_profit** | سود خالص = سود ناخالص - کارمزدها |
| **execution_time_seconds** | مدت زمان از خرید تا فروش |
| **market_conditions** | شرایط بازار شامل قیمت BTC و روند |
| **trade_type** | نوع معامله: grid (خودکار)، manual، stop_loss |
| **simulation** | حالت تست بدون ثبت سفارش واقعی |
| **rebalance** | تنظیم مجدد گرید وقتی قیمت از مرکز فاصله گرفته |

---

## Quick Start برای دولوپر جدید

1. **کد را clone کنید و dependencies نصب کنید:**
   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

2. **Database ست کنید:**
   ```bash
   php artisan migrate
   ```

3. **API Key نوبیتکس بگیرید:**
   از https://nobitex.ir/app/api-keys

4. **env را پر کنید:**
   ```
   NOBITEX_API_KEY=your_key
   TRADING_SIMULATION_MODE=true  # برای تست
   ```

5. **Queue worker را اجرا کنید:**
   ```bash
   php artisan queue:work
   ```

6. **Scheduler را اجرا کنید:**
   ```bash
   php artisan schedule:work
   ```

7. **Filament panel را ببینید:**
   `/admin/bot-monitoring`

---

**نویسنده:** Claude Code
**تاریخ:** 2025-11-18
**نسخه:** 1.0
