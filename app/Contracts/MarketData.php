<?php
// ==========================
// FILE: app/Contracts/MarketData.php
// ==========================

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\OrderBookDto;

/**
 * لایهٔ دادهٔ بازار — منبع واحد قیمت در اپ (WS-first, REST-fallback).
 */
interface MarketData
{
    /** آخرین قیمت معامله‌شده به ریال (IRT) */
    public function getLastPrice(string $symbol): int;

    /** اوردر‌بوک نرمال‌شده */
    public function getOrderBook(string $symbol): OrderBookDto;
}
