<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Market quantity precision — the ONE place an order amount is fitted to the
 * exchange's quantity step (config trading.exchange.precision.{SYMBOL}.qty_decimals).
 *
 * Every order-sending path (CreateOrderDto::toApiPayload for grid orders,
 * NobitexService::placeOrder for exit orders) and the planner go through
 * floor(): an amount is only ever truncated DOWN, never rounded up, so a sized
 * order can never exceed the balance it was computed from. ceil() exists for
 * the few places where rounding up is the safe direction (an inventory-
 * restoring exit buy).
 *
 * Symbols are normalised: "btc-irt", "BTCIRT" and the private-endpoint
 * spelling "BTCRLS" (dst currency 'rls') all resolve to BTCIRT.
 */
final class QtyPrecision
{
    public const DEFAULT_DECIMALS = 8;

    /** Canonical config key for a symbol: upper-case, no dash, RLS → IRT. */
    public static function canonicalSymbol(string $symbol): string
    {
        $s = strtoupper(str_replace(['-', '_', '/'], '', trim($symbol)));
        if (str_ends_with($s, 'RLS')) {
            $s = substr($s, 0, -3) . 'IRT';
        }
        return $s;
    }

    public static function decimalsFor(string $symbol): int
    {
        $dec = config('trading.exchange.precision.' . self::canonicalSymbol($symbol) . '.qty_decimals');
        $dec = $dec === null ? self::DEFAULT_DECIMALS : (int) $dec;
        return max(0, min(18, $dec));
    }

    /** One quantity step, e.g. "0.00000001" for 8 decimals. */
    public static function step(string $symbol): string
    {
        $dec = self::decimalsFor($symbol);
        return $dec === 0 ? '1' : '0.' . str_repeat('0', $dec - 1) . '1';
    }

    /** Truncate DOWN to the market step (never up). */
    public static function floor(string $amount, string $symbol): string
    {
        return Money::floorToScale($amount, self::decimalsFor($symbol));
    }

    /** Round UP to the market step. */
    public static function ceil(string $amount, string $symbol): string
    {
        return Money::ceilToScale($amount, self::decimalsFor($symbol));
    }
}
