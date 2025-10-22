<div dir="rtl">

# 🤖 Trade Griding - ربات معاملاتی Grid Trading نوبیتکس

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat&logo=laravel&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

**ربات خودکار Grid Trading برای صرافی نوبیتکس**

[ویژگی‌ها](#-ویژگیها) • [نصب](#-نصب-و-راهاندازی) • [تنظیمات](#️-تنظیمات) • [استفاده](#-نحوه-استفاده)

</div>

---

## 📖 درباره پروژه

Trade Griding یک پلتفرم معاملاتی خودکار مبتنی بر استراتژی **Grid Trading** است که به‌طور اختصاصی برای صرافی **نوبیتکس** طراحی شده است.

### 💡 استراتژی Grid Trading چیست؟

استراتژی Grid Trading بر اساس قرار دادن سفارش‌های خرید و فروش در فواصل مشخص (Grid) کار می‌کند:
- 📉 سفارش‌های **خرید** زیر قیمت فعلی
- 📈 سفارش‌های **فروش** بالای قیمت فعلی
- 💰 کسب سود از **نوسانات** بازار (نه پیش‌بینی جهت)

> **شعار:** "سود از نوسان، نه از پیش‌بینی"

---

## ✨ ویژگی‌ها

### 🎯 **قابلیت‌های اصلی**
- ✅ **Grid Trading خودکار** با توزیع logarithmic و linear
- ✅ **تنظیمات سفارشی** (فاصله 0.5%-10%, سطوح 4-20)
- ✅ **حالت‌های مختلف** (فقط خرید، فقط فروش، هر دو)
- ✅ **Dry-run Mode** برای تست بدون ریسک
- ✅ **پنل مدیریت** حرفه‌ای با Filament v3

### 🔗 **اتصال به نوبیتکس**
- ✅ **REST API** با مدیریت خطا و auto-retry
- ✅ **WebSocket** برای قیمت‌های real-time
- ✅ **Rate Limiting** هوشمند
- ✅ پشتیبانی از **چند ارز** (BTCIRT, ETHIRT, USDTIRT)

### 📊 **مدیریت سفارش‌ها**
- ✅ ثبت خودکار **CompletedTrade**
- ✅ ساخت خودکار **سفارش مقابل**
- ✅ پیگیری وضعیت real-time
- ✅ تاریخچه کامل معاملات

---

## 🚀 نصب و راه‌اندازی

### پیش‌نیازها
- PHP >= 8.2
- Composer
- MySQL / MariaDB
- Redis (اختیاری)
- Git

### مراحل نصب
```bash
# 1. کلون کردن
git clone https://github.com/laminorr/griding.git
cd griding

# 2. نصب Dependencies
composer install
npm install && npm run build

# 3. تنظیم Environment
cp .env.example .env
php artisan key:generate

# 4. تنظیم دیتابیس در .env
# DB_DATABASE=your_database
# DB_USERNAME=your_username
# DB_PASSWORD=your_password

# 5. اجرای Migrations
php artisan migrate

# 6. راه‌اندازی
php artisan serve
```

---

## ⚙️ تنظیمات

### تنظیم API Key نوبیتکس

در فایل `.env`:
```env
NOBITEX_API_KEY=your_api_key_here
NOBITEX_USE_TESTNET=false
```

### تنظیمات Grid Trading
```env
GRID_DEFAULT_CAPITAL=100000000
GRID_DEFAULT_ACTIVE_PERCENT=30
GRID_DEFAULT_SPACING=1.5
GRID_DEFAULT_LEVELS=10
```

---

## 🎮 نحوه استفاده

1. **ورود به پنل:** `https://your-domain.com/admin`
2. **ایجاد ربات** از منوی ربات‌ها
3. **تست اتصال** با دکمه Test Connection
4. **محاسبه Grid** با Grid Calculator
5. **راه‌اندازی** با Dry-run یا Live Mode

---

## ⚠️ نکات مهم

### امنیت
- ❌ هیچ‌وقت `.env` را commit نکنید
- ✅ API Key را دوره‌ای تغییر دهید
- ✅ از IP Whitelist استفاده کنید

### مدیریت ریسک
- ✅ با مبلغ کم شروع کنید
- ✅ از Dry-run برای تست استفاده کنید
- ✅ Stop Loss مشخص کنید

---

## 📚 مستندات
- [مستندات نوبیتکس](https://docs.nobitex.ir)
- [مستندات Laravel](https://laravel.com/docs)

---

## ⚡ تکنولوژی‌ها
- **Backend:** Laravel 12 + PHP 8.2
- **Admin Panel:** Filament v3.3
- **Database:** MySQL + Redis
- **Frontend:** Livewire + Tailwind CSS

---

<div align="center">

**ساخته شده با ❤️ برای معامله‌گران ایرانی**

</div>

</div>
