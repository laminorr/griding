<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CHARACTERIZATION test suite for {@see \App\Support\Money}.
 *
 * Money is the stateless bcmath wrapper that every price, quantity and profit
 * figure in the bot flows through. It had ZERO coverage. This suite records
 * what the code does *today*, on purpose — where the actual behaviour differs
 * from the naive expectation the assertion LOCKS THE ACTUAL BEHAVIOUR and a
 * `// CHARACTERIZATION:` comment explains the surprise. Nothing here is a
 * request to change Money; several assertions pin latent bugs so a future
 * change to Money will announce itself by breaking a test.
 *
 * Money has no framework dependency, so this extends PHPUnit's TestCase
 * directly (not the Laravel TestCase) to keep the tier fast and app-free.
 *
 * Expected values were computed independently of Money — by hand, by exact
 * decimal arithmetic, or (for the float-cast probes) from PHP's own native
 * float/string behaviour — never by calling Money to define its own oracle.
 *
 * A note on the round()/alignToTick() large-magnitude cases: both methods cast
 * an exact bcmath string to a native float and back to a string mid-computation.
 * At IRT *notional* magnitudes that string comes back in scientific notation
 * (PHP's default `precision=14`), and PHP 8's strict bcmath rejects a
 * non-well-formed operand with a \ValueError. Those assertions therefore pin a
 * \ValueError and assume PHP 8 bcmath semantics (the project targets PHP 8.3+).
 */
final class MoneyTest extends TestCase
{
    // =====================================================================
    // normalize() — the single most important function: it must stop bcmath
    // ever receiving scientific notation.
    // =====================================================================

    #[DataProvider('scientificFloatProvider')]
    public function test_normalize_renders_small_floats_without_scientific_notation(float $input, string $expected): void
    {
        $out = Money::normalize($input);

        // The whole reason normalize() exists: no 'E'/'e' may survive.
        $this->assertStringNotContainsStringIgnoringCase('e', $out, 'normalize must never emit scientific notation');
        $this->assertSame($expected, $out);
    }

    public static function scientificFloatProvider(): array
    {
        return [
            '1.0E-7'        => [1.0E-7, '0.0000001'],
            '1e-15'         => [1e-15, '0.000000000000001'],
            '0.0000001'     => [0.0000001, '0.0000001'],
            '2.5e-9'        => [2.5e-9, '0.0000000025'],
            'negative 1e-7' => [-1.0E-7, '-0.0000001'],
            'negative 2.5e-9' => [-2.5e-9, '-0.0000000025'],
        ];
    }

    #[DataProvider('largeFloatProvider')]
    public function test_normalize_renders_large_floats_as_fixed_point(float $input, string $expected): void
    {
        $out = Money::normalize($input);
        $this->assertStringNotContainsStringIgnoringCase('e', $out);
        $this->assertSame($expected, $out);
    }

    public static function largeFloatProvider(): array
    {
        // These specific doubles render cleanly under sprintf('%.20F', ...).
        // Note the value still travels through the lossy float->string channel
        // (see the class docblock): prefer passing strings when you have them.
        return [
            '1e20'          => [1e20, '100000000000000000000'],
            '1.23e18'       => [1.23e18, '1230000000000000000'],
            'negative 1.23e18' => [-1.23e18, '-1230000000000000000'],
        ];
    }

    public function test_normalize_passes_through_ints(): void
    {
        $this->assertSame('0', Money::normalize(0));
        $this->assertSame('0', Money::normalize(-0));            // -0 as int literal is just 0
        $this->assertSame('98500000000', Money::normalize(98500000000));
        $this->assertSame('-98500000000', Money::normalize(-98500000000));
    }

    public function test_normalize_passes_through_strings_verbatim(): void
    {
        // CHARACTERIZATION: for a *string* input normalize() does NO validation
        // and NO canonicalisation — it returns the argument verbatim. The
        // docblock's promise of a "canonical bcmath-safe string" only applies
        // to int/float inputs. A caller that hands normalize() a dirty string
        // gets that dirty string straight back.
        $this->assertSame('0', Money::normalize('0'));
        $this->assertSame('-0', Money::normalize('-0'));         // NOT collapsed to '0'
        $this->assertSame('00123.4500', Money::normalize('00123.4500')); // leading/trailing zeros kept
        $this->assertSame('  5  ', Money::normalize('  5  '));   // whitespace NOT trimmed
    }

    public function test_normalize_does_not_reject_a_non_numeric_string(): void
    {
        // CHARACTERIZATION / latent-bug candidate: a garbage string is accepted
        // and returned unchanged. It only detonates later, inside a bc* call.
        $this->assertSame('not-a-number', Money::normalize('not-a-number'));
    }

    #[DataProvider('normalizeRejectionProvider')]
    public function test_normalize_rejects_non_numbers(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::normalize($input);
    }

    public static function normalizeRejectionProvider(): array
    {
        return [
            'NAN'    => [NAN],
            'INF'    => [INF],
            '-INF'   => [-INF],
            'null'   => [null],
            'true'   => [true],
            'false'  => [false],
            'array'  => [[1, 2]],
            'object' => [new \stdClass()],
        ];
    }

    public function test_normalize_rejection_messages_are_specific(): void
    {
        // Cheap message pins so a refactor cannot silently reclassify the guard.
        try {
            Money::normalize(NAN);
            $this->fail('expected InvalidArgumentException for NAN');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('NAN/INF', $e->getMessage());
        }

        try {
            Money::normalize(null);
            $this->fail('expected InvalidArgumentException for null');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('null', $e->getMessage());
        }

        try {
            Money::normalize(true);
            $this->fail('expected InvalidArgumentException for bool');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bool', $e->getMessage());
        }
    }

    // =====================================================================
    // Arithmetic at IRT scale.
    // Expected results are computed here independently (by hand / exact
    // decimal), NOT by calling Money.
    // =====================================================================

    public function test_add_and_sub_at_btcirt_magnitude(): void
    {
        // ~11-digit BTCIRT magnitudes.
        $this->assertSame('100000000000', Money::add('98500000000', '1500000000'));
        $this->assertSame('97000000000', Money::sub('98500000000', '1500000000'));
    }

    public function test_add_at_twenty_digit_overflow_history(): void
    {
        // 99,999,999,999,999,999,999 + 1 = 100,000,000,000,000,000,000 (10^20).
        // This is the class of value that silently truncated to signed 32-bit
        // before the BCMath migration; assert the exact 21-digit carry survives.
        $this->assertSame('100000000000000000000', Money::add('99999999999999999999', '1'));
    }

    public function test_mul_at_twenty_digit_operands(): void
    {
        // 12345678901234567890 * 98765432109876543210, full exact product.
        // 40-digit result — well beyond any native integer or IEEE-754 double.
        $this->assertSame(
            '1219326311370217952237463801111263526900',
            Money::mul('12345678901234567890', '98765432109876543210')
        );
    }

    public function test_arithmetic_across_the_2_53_boundary(): void
    {
        // 2^53 = 9007199254740992 is the last integer a double represents
        // exactly. bcmath is unaffected: the +1 and -1 land exactly.
        $this->assertSame('9007199254740993', Money::add('9007199254740992', '1'));
        $this->assertSame('9007199254740992', Money::sub('9007199254740993', '1'));
    }

    public function test_mul_with_mixed_scale_string_inputs(): void
    {
        // price (11-digit int string) * qty (8-dp string). Exact product is
        // 98500000000 * 0.00006841 = 6738385 (exact), scale 20 then trimmed.
        $this->assertSame('6738385', Money::mul('98500000000', '0.00006841'));
    }

    public function test_div_truncates_and_does_not_round(): void
    {
        // CHARACTERIZATION: bcdiv truncates toward zero; it does NOT round.
        // 1/3 comes back as twenty 3s (not ...34), 2/3 as twenty 6s (not ...67).
        $this->assertSame('0.33333333333333333333', Money::div('1', '3'));
        $this->assertSame('0.66666666666666666666', Money::div('2', '3'));
        $this->assertSame('-0.33333333333333333333', Money::div('-1', '3'));
    }

    public function test_div_exact_and_scale(): void
    {
        $this->assertSame('2.5', Money::div('10', '4'));           // exact, trailing zeros trimmed
        $this->assertSame('49250000000', Money::div('98500000000', '2'));
    }

    public function test_div_at_a_custom_scale_truncates_to_that_scale(): void
    {
        // 1/3 at scale 4 -> 0.3333 (truncated at the 4th place, not rounded).
        $this->assertSame('0.3333', Money::div('1', '3', 4));
    }

    public function test_negative_operand_sign_combinations(): void
    {
        $this->assertSame('197000000000', Money::mul('-98500000000', '-2')); // neg*neg = pos
        $this->assertSame('-197000000000', Money::mul('98500000000', '-2')); // pos*neg = neg
        $this->assertSame('-97000000000', Money::add('-98500000000', '1500000000'));
    }

    public function test_div_by_zero_throws_division_by_zero_error(): void
    {
        // Explicitly NOT expecting "0": Money::div guards and throws.
        $this->expectException(\DivisionByZeroError::class);
        Money::div('5', '0');
    }

    public function test_div_by_scaled_zero_also_throws(): void
    {
        // A divisor that is zero only after scale truncation is still zero.
        $this->expectException(\DivisionByZeroError::class);
        Money::div('5', '0.00000000000000000000');
    }

    // =====================================================================
    // compare / min / max / sign predicates / abs
    // =====================================================================

    public function test_compare_returns_exactly_minus_one_zero_one(): void
    {
        // bccomp guarantees -1/0/1, so pin the exact integers.
        $this->assertSame(-1, Money::compare('1', '2'));
        $this->assertSame(0, Money::compare('2', '2'));
        $this->assertSame(1, Money::compare('3', '2'));
        $this->assertSame(1, Money::compare('99999999999999999999', '99999999999999999998'));
    }

    public function test_compare_is_numeric_not_lexical_for_trailing_and_leading_zeros(): void
    {
        // This is exactly what the fill engine relies on: "100" and "100.00"
        // are equal, and "007" equals "7". A lexical/string compare would not.
        $this->assertSame(0, Money::compare('100', '100.00'));
        $this->assertSame(0, Money::compare('007', '7'));
    }

    public function test_min_and_max_with_mixed_values_and_negatives(): void
    {
        $this->assertSame('3', Money::min('5', '3', '9', '3'));
        $this->assertSame('9', Money::max('5', '3', '9', '3'));
        $this->assertSame('-5', Money::min('-5', '-3', '0'));
        $this->assertSame('0', Money::max('-5', '-3', '0'));
    }

    public function test_min_and_max_return_first_tie_verbatim(): void
    {
        // CHARACTERIZATION: comparison is strict (< / >), so among numeric ties
        // the FIRST-seen operand wins and is returned verbatim, unformatted.
        // min/max never re-canonicalise their output.
        $this->assertSame('100.00', Money::min('100.00', '100'));
        $this->assertSame('100.00', Money::max('100.00', '100'));
    }

    public function test_min_and_max_reject_empty_argument_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::min();
    }

    #[DataProvider('signPredicateProvider')]
    public function test_sign_predicates(string $value, bool $zero, bool $positive, bool $negative): void
    {
        $this->assertSame($zero, Money::isZero($value), "isZero($value)");
        $this->assertSame($positive, Money::isPositive($value), "isPositive($value)");
        $this->assertSame($negative, Money::isNegative($value), "isNegative($value)");
    }

    public static function signPredicateProvider(): array
    {
        return [
            // every spelling of zero reports isZero true, isPositive/isNegative false
            '"0"'                          => ['0', true, false, false],
            '"-0"'                         => ['-0', true, false, false],
            'twenty-zero decimal'          => ['0.00000000000000000000', true, false, false],

            // CHARACTERIZATION: 1e-20 is exactly at the scale-20 resolution, so
            // it is a *positive* value, not zero...
            '1e-20 (at scale limit)'       => ['0.00000000000000000001', false, true, false],
            // ...but 1e-21 is below the working scale and truncates to zero, so
            // sub-scale "dust" compares equal to zero. This is a direct
            // consequence of the default scale of 20.
            '1e-21 (below scale)'          => ['0.000000000000000000001', true, false, false],

            'small negative'               => ['-0.5', false, false, true],
            'small positive'               => ['5', false, true, false],
        ];
    }

    public function test_abs(): void
    {
        $this->assertSame('5', Money::abs('-5'));
        $this->assertSame('5', Money::abs('5'));
        $this->assertSame('0', Money::abs('0'));
        // CHARACTERIZATION: abs works purely on the string. It strips a leading
        // '-' only; it does not re-normalise, so "-0.00" -> "0.00" (decimals kept).
        $this->assertSame('0', Money::abs('-0'));
        $this->assertSame('0.00', Money::abs('-0.00'));
        // 20-digit negative survives exactly.
        $this->assertSame('99999999999999999999', Money::abs('-99999999999999999999'));
    }

    // =====================================================================
    // round() — mixes native round((float)...) into a bcmath expression.
    // =====================================================================

    public function test_round_uses_half_away_from_zero(): void
    {
        // CHARACTERIZATION: the half-way rule is native PHP round() =
        // half-away-from-zero (NOT banker's rounding), and it applies in both
        // directions. The Persian docblock's "round half-up" means
        // away-from-zero, including -2.5 -> -3.
        $this->assertSame('3', Money::round('2.5', 0));
        $this->assertSame('4', Money::round('3.5', 0));
        $this->assertSame('-3', Money::round('-2.5', 0));
    }

    public function test_round_at_various_scales(): void
    {
        // Inputs are exact decimal *strings*, so the classic float trap
        // (1.005 stored as 1.00499...) does NOT bite here: bcmul is exact, so
        // 1.005 rounds up to 1.01.
        $this->assertSame('1.01', Money::round('1.005', 2));
        $this->assertSame('1.23', Money::round('1.2345', 2));
        $this->assertSame('0.0001', Money::round('0.00006841', 4));
        // A value already at IRT price magnitude rounds cleanly (11 digits stay
        // out of scientific-notation range).
        $this->assertSame('98500000001', Money::round('98500000000.5', 0));
    }

    public function test_round_rejects_negative_scale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::round('1.5', -1);
    }

    #[DataProvider('roundLargeValueProvider')]
    public function test_round_throws_on_values_that_force_scientific_notation(string $value): void
    {
        // CHARACTERIZATION / LATENT BUG: round() casts its intermediate bcmath
        // product to a native float and back to a string via (string). Once the
        // magnitude reaches ~1e15 that string comes out in scientific notation
        // ("1.0E+18") under PHP's default precision=14, and PHP 8's strict
        // bcmath rejects the non-well-formed operand with a \ValueError.
        // Independently, above 2^53 the float cast is already lossy. Net effect:
        // round() is UNUSABLE at IRT notional magnitudes (products/notionals
        // routinely exceed 1e15). Real BTCIRT *prices* (~1e11) are unaffected.
        $this->expectException(\ValueError::class);
        Money::round($value, 0);
    }

    public static function roundLargeValueProvider(): array
    {
        return [
            'just above 2^53' => ['9007199254740993'],
            '1e18'            => ['1000000000000000000'],
            '1e18 + .5'       => ['1000000000000000000.5'],
            '20-digit'        => ['12345678901234567890'],
        ];
    }

    // =====================================================================
    // alignToTick() — also casts to float for floor/ceil/round.
    // =====================================================================

    public function test_align_to_tick_floor_ceil_round_at_real_magnitude(): void
    {
        // BTCIRT default tick = 10. buys floor, sells ceil (GridPlanner).
        $this->assertSame('98500000000', Money::alignToTick('98500000007', '10', 'floor'));
        $this->assertSame('98500000010', Money::alignToTick('98500000007', '10', 'ceil'));
        // nearest: 98500000005 / 10 = 9850000000.5 -> half-away -> 9850000001 -> *10
        $this->assertSame('98500000010', Money::alignToTick('98500000005', '10', 'round'));
    }

    public function test_align_to_tick_is_idempotent_on_a_value_already_on_a_tick(): void
    {
        $this->assertSame('98500000010', Money::alignToTick('98500000010', '10', 'floor'));
        $this->assertSame('98500000010', Money::alignToTick('98500000010', '10', 'ceil'));
    }

    public function test_align_to_tick_with_tick_one(): void
    {
        $this->assertSame('7', Money::alignToTick('7', '1', 'floor'));
        $this->assertSame('7', Money::alignToTick('7', '1', 'ceil'));
    }

    public function test_align_to_tick_unknown_mode_silently_falls_back_to_floor(): void
    {
        // CHARACTERIZATION: the switch has no guard for an unrecognised mode;
        // anything that is not 'ceil'/'round' hits the default and FLOORS.
        // A typo'd mode does not raise — it quietly rounds down.
        $this->assertSame('98500000000', Money::alignToTick('98500000007', '10', 'banker'));
        $this->assertSame('98500000000', Money::alignToTick('98500000007', '10', 'nearest'));
    }

    #[DataProvider('nonPositiveTickProvider')]
    public function test_align_to_tick_guards_non_positive_tick(string $tick): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::alignToTick('7', $tick, 'floor');
    }

    public static function nonPositiveTickProvider(): array
    {
        return [
            'zero tick'     => ['0'],
            'negative tick' => ['-1'],
        ];
    }

    #[DataProvider('alignLargeValueProvider')]
    public function test_align_to_tick_throws_on_values_that_force_scientific_notation(string $value, string $mode): void
    {
        // CHARACTERIZATION / LATENT BUG: same float round-trip flaw as round().
        // Once value/tick reaches ~1e15 the (string)floor/ceil/round((float)...)
        // step yields scientific notation and PHP 8 bcmath (bcmul) rejects it
        // with a \ValueError. alignToTick is therefore unsafe above ~1e16 for
        // tick 10 — fine at real BTCIRT prices, broken at large notionals.
        $this->expectException(\ValueError::class);
        Money::alignToTick($value, '10', $mode);
    }

    public static function alignLargeValueProvider(): array
    {
        return [
            'floor 1e18+7' => ['1000000000000000007', 'floor'],
            'ceil 1e18+7'  => ['1000000000000000007', 'ceil'],
        ];
    }

    // =====================================================================
    // irtToBase / baseToIrt
    // =====================================================================

    public function test_irt_base_round_trip_survives(): void
    {
        // A realistic BTC quantity at a realistic BTCIRT price that divides
        // exactly: 0.00006841 BTC * 98,500,000,000 IRT = 6,738,385 IRT, and
        // back again to 0.00006841 BTC. The value survives the round trip.
        $priceIrt = 98500000000;

        $notionalIrt = Money::baseToIrt('0.00006841', $priceIrt);
        $this->assertSame('6738385', $notionalIrt);

        $backToBase = Money::irtToBase((int) $notionalIrt, $priceIrt, 8);
        $this->assertSame('0.00006841', $backToBase);
    }

    public function test_irt_to_base_default_scale_is_eight(): void
    {
        // 674,350,000,000 IRT / 98,500,000,000 IRT-per-BTC = 6.84619289... ,
        // truncated to 8 dp.
        $this->assertSame('6.84619289', Money::irtToBase(674350000000, 98500000000));
    }

    #[DataProvider('nonPositivePriceProvider')]
    public function test_irt_to_base_guards_non_positive_price(int $price): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::irtToBase(1000, $price);
    }

    #[DataProvider('nonPositivePriceProvider')]
    public function test_base_to_irt_guards_non_positive_price(int $price): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::baseToIrt('1', $price);
    }

    public static function nonPositivePriceProvider(): array
    {
        return [
            'zero price'     => [0],
            'negative price' => [-5],
        ];
    }

    // =====================================================================
    // trimZeros
    // =====================================================================

    #[DataProvider('trimZerosProvider')]
    public function test_trim_zeros(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::trimZeros($input));
    }

    public static function trimZerosProvider(): array
    {
        return [
            'trailing decimals'        => ['5000.00', '5000'],
            'partial trailing'         => ['0.00010945000', '0.00010945'],
            'zero with decimals'       => ['0.0', '0'],
            'all-zero decimals'        => ['0.000', '0'],
            // CRITICAL: an integer string with meaningful trailing zeros must
            // NOT be stripped — there is no decimal point, so "1000" stays "1000".
            'integer trailing zeros'   => ['1000', '1000'],
            'integer no decimals'      => ['123', '123'],
            'negative trailing'        => ['-0.500', '-0.5'],
            'negative integerised'     => ['-100.00', '-100'],
            'keep significant decimal' => ['100.10', '100.1'],
        ];
    }
}
