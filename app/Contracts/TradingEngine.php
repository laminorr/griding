<?php
// ==========================
// FILE: app/Contracts/TradingEngine.php
// ==========================

declare(strict_types=1);

namespace App\Contracts;

use App\Models\BotConfig;

/**
 * موتور ترید گرید — ورود/نظارت/تنظیم مجدد گرید بر اساس قیمت.
 */
interface TradingEngine
{
    /** راه‌اندازی گرید برای یک bot مشخص (ثبت اولیه سفارش‌ها) */
    public function initializeGrid(BotConfig $bot): void;

    /** بررسی و مدیریت معاملات (پر شدن سفارش‌ها، جایگزینی‌ها، ثبت کامل‌شده‌ها) */
    public function checkAndManageTrades(BotConfig $bot): void;

    /** بررسی نیاز به تنظیم مجدد و انجام Rebalance در صورت لزوم */
    public function rebalanceIfNeeded(BotConfig $bot): void;
}