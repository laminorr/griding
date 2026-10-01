<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converts JSON numbers that PHP decoded as floats (e.g. the Nobitex WS candle
 * channel's o/h/l/c/v doubles) into plain decimal strings:
 *   - never scientific notation (0.00012 -> "0.00012", 2.153e11 -> "215300000000")
 *   - integral values carry no decimal point (6240000001.0 -> "6240000001")
 *   - no float noise: rounded to 15 significant digits (DBL_DIG), the most a
 *     double round-trips exactly, so 0.1 + 0.2 -> "0.3" and 1.26 -> "1.26".
 *
 * A plain (string) cast or number_format() with a fixed scale is not used on
 * purpose: the former can emit "1.0E-5"/noise depending on precision ini, the
 * latter invents or drops digits.
 */
final class DecimalString
{
    /** Significant digits kept (DBL_DIG): any <=15-digit decimal round-trips through a double. */
    private const SIGNIFICANT_DIGITS = 15;

    /**
     * @param int|float|string $value  int, finite float, or numeric string
     * @throws \InvalidArgumentException for NaN/INF or non-numeric input
     */
    public static function fromNumber(int|float|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) === 1) {
                return self::stripZeros($trimmed);
            }
            if (!is_numeric($trimmed)) {
                throw new \InvalidArgumentException('Not a numeric value: ' . $value);
            }
            $value = (float) $trimmed;
        }

        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Non-finite number cannot be represented as a decimal string');
        }

        if ($value == 0.0) {
            return '0'; // also folds -0.0
        }

        // d.dddddddddddddde±X — exactly SIGNIFICANT_DIGITS digits, locale-proofed.
        $sci = sprintf('%.' . (self::SIGNIFICANT_DIGITS - 1) . 'e', $value);
        if (preg_match('/^(-?)(\d)[.,](\d+)e([+-]\d+)$/', $sci, $m) !== 1) {
            throw new \InvalidArgumentException('Unexpected float format: ' . $sci);
        }

        $sign     = $m[1];
        $digits   = $m[2] . $m[3];
        $exponent = (int) $m[4];
        $pointAt  = $exponent + 1; // digits before the decimal point

        if ($pointAt <= 0) {
            $plain = '0.' . str_repeat('0', -$pointAt) . $digits;
        } elseif ($pointAt >= strlen($digits)) {
            $plain = $digits . str_repeat('0', $pointAt - strlen($digits));
        } else {
            $plain = substr($digits, 0, $pointAt) . '.' . substr($digits, $pointAt);
        }

        $plain = self::stripZeros($plain);

        return $plain === '0' ? '0' : $sign . $plain;
    }

    /** Drop trailing fractional zeros (and a bare '.'); keep integer digits intact. */
    private static function stripZeros(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $unsigned = $negative ? substr($decimal, 1) : $decimal;

        if (str_contains($unsigned, '.')) {
            $unsigned = rtrim(rtrim($unsigned, '0'), '.');
        }
        $unsigned = ltrim($unsigned, '0');
        if ($unsigned === '' || $unsigned[0] === '.') {
            $unsigned = '0' . $unsigned;
        }

        if ($unsigned === '0') {
            return '0';
        }

        return ($negative ? '-' : '') . $unsigned;
    }
}
