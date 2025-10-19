<?php

namespace App\Helpers;

class GridHelper
{
    /**
     * تبدیل ریال به دلار (تقریبی)
     */
    public static function irtToUsd($irtAmount)
    {
        $usdRate = 42000; // نرخ تقریبی
        return round($irtAmount / $usdRate, 2);
    }
    
    /**
     * تبدیل دلار به ریال
     */
    public static function usdToIrt($usdAmount)
    {
        $usdRate = 42000;
        return round($usdAmount * $usdRate);
    }
    
    /**
     * فرمت عدد ریالی
     */
    public static function formatIrt($amount)
    {
        return number_format($amount) . ' ریال';
    }
    
    /**
     * فرمت درصد
     */
    public static function formatPercent($percent, $decimals = 2)
    {
        return number_format($percent, $decimals) . '%';
    }
    
    /**
     * محاسبه فاصله درصدی بین دو قیمت
     */
    public static function calculatePriceDistance($price1, $price2)
    {
        if ($price1 == 0) return 0;
        return (($price2 - $price1) / $price1) * 100;
    }
    
    /**
     * تشخیص رنگ بر اساس سود/ضرر
     */
    public static function getProfitColor($amount)
    {
        if ($amount > 0) return 'text-green-600';
        if ($amount < 0) return 'text-red-600';
        return 'text-gray-600';
    }
    
    /**
     * تشخیص سطح ریسک
     */
    public static function getRiskBadgeColor($riskLevel)
    {
        switch ($riskLevel) {
            case 'خیلی کم': return 'bg-green-100 text-green-800';
            case 'کم': return 'bg-blue-100 text-blue-800';
            case 'متوسط': return 'bg-yellow-100 text-yellow-800';
            case 'بالا': return 'bg-orange-100 text-orange-800';
            case 'خیلی بالا': return 'bg-red-100 text-red-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    }
}