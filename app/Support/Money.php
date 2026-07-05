<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Central arbitrary-precision money/decimal helper (a thin, stateless wrapper around ext-bcmath).
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * Every monetary value in this project falls into one of two categories that native PHP
 * arithmetic handles badly:
 *
 *   1. IRT prices — large integers (~100 billion; up to 20-digit decimals). On some 32-bit
 *      code paths these silently overflow/truncate a signed integer, corrupting order prices.
 *   2. Crypto amounts — small values with up to 8 fractional digits (e.g. 0.00006841).
 *      As IEEE-754 doubles these accumulate rounding error (the classic 0.1 + 0.2 problem),
 *      so sums/comparisons drift.
 *
 * To fix both classes of bug we keep money as *strings* and do all math through bcmath, which
 * is exact and unbounded. This class is the single place that wraps bcmath so callers never
 * touch the bc* functions directly and never have to remember a scale. It is being introduced
 * as Step 1 of the Phase 10 BCMath migration: this step is purely additive (it adds the helper
 * that later steps will route existing calculations through) so no runtime behaviour changes yet.
 *
 * WHY scale = 20 BY DEFAULT
 * -------------------------
 * IRT integers reach ~20 digits and crypto amounts carry 8 fractional digits. A default scale of
 * 20 is deliberately generous: it is more than enough to preserve every meaningful digit of both
 * value classes through a chain of intermediate operations, so precision is never lost *before* a
 * caller rounds to a market's display/tick precision at the very end. Trailing zeros produced by
 * the wide scale are stripped via {@see self::trimZeros()} so the numeric value is unchanged but
 * the string stays clean.
 *
 * DESIGN NOTES
 * ------------
 *  - Pure PHP: this class has no Laravel dependency, so it is trivially unit-testable in isolation.
 *  - Stateless: every method is static with no shared state; calls are independent and pure.
 *  - Inputs are decimal strings. Use {@see self::normalize()} to turn an int/float/string into a
 *    canonical bcmath-safe string (notably, floats are rendered without scientific notation, which
 *    bcmath cannot parse).
 */
final class Money
{
    /**
     * Default working scale (fractional digits) for arithmetic and comparisons.
     * See the class docblock for the rationale behind 20.
     */
    public const DEFAULT_SCALE = 20;

    // ---------------------------------------------------------------------
    // Arithmetic
    // ---------------------------------------------------------------------

    /**
     * Add two decimal strings: $a + $b.
     *
     * @param string $a    Left operand (decimal string).
     * @param string $b    Right operand (decimal string).
     * @param int    $scale Fractional digits to compute at (default 20).
     * @return string Sum, with insignificant trailing zeros trimmed (numeric value unchanged).
     */
    public static function add(string $a, string $b, int $scale = self::DEFAULT_SCALE): string
    {
        return self::trimZeros(bcadd($a, $b, $scale));
    }

    /**
     * Subtract two decimal strings: $a - $b.
     *
     * @param string $a    Minuend (decimal string).
     * @param string $b    Subtrahend (decimal string).
     * @param int    $scale Fractional digits to compute at (default 20).
     * @return string Difference, with insignificant trailing zeros trimmed.
     */
    public static function sub(string $a, string $b, int $scale = self::DEFAULT_SCALE): string
    {
        return self::trimZeros(bcsub($a, $b, $scale));
    }

    /**
     * Multiply two decimal strings: $a * $b.
     *
     * @param string $a    Left factor (decimal string).
     * @param string $b    Right factor (decimal string).
     * @param int    $scale Fractional digits to compute at (default 20).
     * @return string Product, with insignificant trailing zeros trimmed.
     */
    public static function mul(string $a, string $b, int $scale = self::DEFAULT_SCALE): string
    {
        return self::trimZeros(bcmul($a, $b, $scale));
    }

    /**
     * Divide two decimal strings: $a / $b.
     *
     * Unlike a bare bcdiv (which returns "0"/null and merely warns on a zero divisor), this
     * throws so a division-by-zero bug can never masquerade as a legitimate zero result.
     *
     * @param string $a    Dividend (decimal string).
     * @param string $b    Divisor (decimal string). Must not be zero.
     * @param int    $scale Fractional digits to compute at (default 20).
     * @return string Quotient (bcmath truncates, it does not round), trailing zeros trimmed.
     * @throws \DivisionByZeroError If $b is zero (at the given scale).
     */
    public static function div(string $a, string $b, int $scale = self::DEFAULT_SCALE): string
    {
        if (bccomp($b, '0', $scale) === 0) {
            throw new \DivisionByZeroError('Money::div() called with a zero divisor.');
        }
        return self::trimZeros(bcdiv($a, $b, $scale));
    }

    // ---------------------------------------------------------------------
    // Comparison
    // ---------------------------------------------------------------------

    /**
     * Compare two decimal strings numerically.
     *
     * Uses bccomp so "100" vs "50" compares by value, avoiding the string/lexical trap where
     * "100" < "50" character-by-character.
     *
     * @param string $a    Left operand.
     * @param string $b    Right operand.
     * @param int    $scale Fractional digits to compare at (default 20).
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b.
     */
    public static function compare(string $a, string $b, int $scale = self::DEFAULT_SCALE): int
    {
        return bccomp($a, $b, $scale);
    }

    /**
     * Return the numerically smallest of the given decimal strings.
     *
     * @param string ...$values One or more decimal strings.
     * @return string The smallest value, returned verbatim (not re-formatted).
     * @throws \InvalidArgumentException If called with no arguments.
     */
    public static function min(string ...$values): string
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Money::min() requires at least one value.');
        }
        $smallest = $values[0];
        foreach ($values as $value) {
            if (bccomp($value, $smallest, self::DEFAULT_SCALE) < 0) {
                $smallest = $value;
            }
        }
        return $smallest;
    }

    /**
     * Return the numerically largest of the given decimal strings.
     *
     * @param string ...$values One or more decimal strings.
     * @return string The largest value, returned verbatim (not re-formatted).
     * @throws \InvalidArgumentException If called with no arguments.
     */
    public static function max(string ...$values): string
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Money::max() requires at least one value.');
        }
        $largest = $values[0];
        foreach ($values as $value) {
            if (bccomp($value, $largest, self::DEFAULT_SCALE) > 0) {
                $largest = $value;
            }
        }
        return $largest;
    }

    // ---------------------------------------------------------------------
    // Sign predicates
    // ---------------------------------------------------------------------

    /**
     * True if the value is numerically zero.
     *
     * Because it compares by value, every spelling of zero ("0", "0.00000000", "-0") reports true.
     *
     * @param string $a    Decimal string.
     * @param int    $scale Fractional digits to compare at (default 20).
     */
    public static function isZero(string $a, int $scale = self::DEFAULT_SCALE): bool
    {
        return bccomp($a, '0', $scale) === 0;
    }

    /**
     * True if the value is strictly greater than zero.
     *
     * @param string $a    Decimal string.
     * @param int    $scale Fractional digits to compare at (default 20).
     */
    public static function isPositive(string $a, int $scale = self::DEFAULT_SCALE): bool
    {
        return bccomp($a, '0', $scale) > 0;
    }

    /**
     * True if the value is strictly less than zero.
     *
     * @param string $a    Decimal string.
     * @param int    $scale Fractional digits to compare at (default 20).
     */
    public static function isNegative(string $a, int $scale = self::DEFAULT_SCALE): bool
    {
        return bccomp($a, '0', $scale) < 0;
    }

    // ---------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------

    /**
     * Absolute value: strip a leading minus sign.
     *
     * Works purely on the string so no precision is lost. Negative zero ("-0", "-0.00") becomes
     * its positive spelling ("0", "0.00"); a value with no sign is returned unchanged.
     *
     * @param string $a Decimal string.
     * @return string The value without a leading '-'.
     */
    public static function abs(string $a): string
    {
        return str_starts_with($a, '-') ? substr($a, 1) : $a;
    }

    /**
     * Coerce an int/float/string into a canonical decimal string safe to feed to bcmath.
     *
     * Edge cases that matter:
     *  - float: rendered with sprintf('%.20F', ...) so a small magnitude never comes out in
     *    scientific notation (e.g. 1.0E-7), which bcmath cannot parse; the fixed-notation string
     *    is then stripped of insignificant trailing zeros. Note that a float still carries its
     *    IEEE-754 representation error into the string — prefer passing money as a string when
     *    you already have one.
     *  - non-finite float (NAN/INF): rejected, since it has no valid decimal representation.
     *  - null / bool / array / object / resource: rejected — these are not numbers.
     *
     * @param mixed $v Value to normalize (int, float, or already-normalized string).
     * @return string Canonical decimal string.
     * @throws \InvalidArgumentException If $v is null, a bool, a non-finite float, or a non-scalar.
     */
    public static function normalize(mixed $v): string
    {
        if ($v === null) {
            throw new \InvalidArgumentException('Money::normalize() does not accept null.');
        }
        if (is_bool($v)) {
            throw new \InvalidArgumentException('Money::normalize() does not accept bool.');
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v)) {
                throw new \InvalidArgumentException('Money::normalize() cannot represent NAN/INF.');
            }
            // %.20F guarantees fixed-point (never "1.0E-7"); trimZeros keeps the value tidy.
            return self::trimZeros(sprintf('%.20F', $v));
        }
        if (is_string($v)) {
            return $v;
        }
        throw new \InvalidArgumentException(
            'Money::normalize() accepts int|float|string, got ' . get_debug_type($v) . '.'
        );
    }

    // ---------------------------------------------------------------------
    // Existing Phase 9 helpers (preserved; used by tick-size / IRT conversions)
    // ---------------------------------------------------------------------

    /**
     * گرد کردن مقدار به دقت اعشاری مشخص (round half-up).
     */
    public static function round(string $value, int $scale): string
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException('Scale must be non-negative.');
        }
        $factor = bcpow('10', (string) $scale, 0);
        $rounded = bcdiv((string) round((float) bcmul($value, $factor, $scale + 1)), $factor, $scale);
        return self::trimZeros($rounded);
    }

    /**
     * نشاندن مقدار روی tick-size (مرحله قیمتی مارکت)
     * - mode: floor|ceil|round
     */
    public static function alignToTick(string $value, string $tickSize, string $mode = 'floor'): string
    {
        if (bccomp($tickSize, '0', 8) <= 0) {
            throw new \InvalidArgumentException('Tick size must be > 0');
        }

        $div = bcdiv($value, $tickSize, 8);
        switch (strtolower($mode)) {
            case 'ceil':
                $div = (string) ceil((float) $div);
                break;
            case 'round':
                $div = (string) round((float) $div);
                break;
            default: // floor
                $div = (string) floor((float) $div);
        }
        $aligned = bcmul($div, $tickSize, 8);
        return self::trimZeros($aligned);
    }

    /**
     * تبدیل ریال (IRT) به BTC/USDT/... با دقت بالا.
     * @param int $priceIRT قیمت 1 واحد ارز پایه به ریال (IRT)
     */
    public static function irtToBase(int $irtAmount, int $priceIRT, int $scale = 8): string
    {
        if ($priceIRT <= 0) {
            throw new \InvalidArgumentException('Price must be > 0');
        }
        return self::trimZeros(bcdiv((string) $irtAmount, (string) $priceIRT, $scale));
    }

    /**
     * تبدیل BTC/USDT/... به ریال (IRT) با دقت بالا.
     * @param string $amountBase مقدار ارز پایه (decimal string)
     * @param int $priceIRT قیمت 1 واحد ارز پایه به ریال (IRT)
     */
    public static function baseToIrt(string $amountBase, int $priceIRT, int $scale = 0): string
    {
        if ($priceIRT <= 0) {
            throw new \InvalidArgumentException('Price must be > 0');
        }
        return self::trimZeros(bcmul($amountBase, (string) $priceIRT, $scale));
    }

    /**
     * حذف صفرهای اضافه در انتهای رشته اعشاری.
     *
     * Trims insignificant trailing zeros (and a now-bare decimal point) without changing the
     * numeric value: "5000.00" -> "5000", "0.00010945000" -> "0.00010945", "0.0" -> "0".
     */
    public static function trimZeros(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value;
    }
}
