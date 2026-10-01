<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalStringTest extends TestCase
{
    /** @return array<string,array{0:int|float|string,1:string}> */
    public static function cases(): array
    {
        return [
            'integral double, no decimal point' => [6240000001.0, '6240000001'],
            'simple fraction'                   => [1.26, '1.26'],
            'small fraction, no sci notation'   => [0.00012, '0.00012'],
            'large integral, no sci notation'   => [215300000000.0, '215300000000'],
            'tiny value, no sci notation'       => [1.0E-8, '0.00000001'],
            'huge value, no sci notation'       => [1.0E+20, '100000000000000000000'],
            'float noise does not leak'         => [0.1 + 0.2, '0.3'],
            'noise on large price'              => [6238031033.0 + 0.1 - 0.1, '6238031033'],
            'many significant digits kept'      => [123456789.123456, '123456789.123456'],
            'negative'                          => [-0.5, '-0.5'],
            'negative zero'                     => [-0.0, '0'],
            'zero'                              => [0.0, '0'],
            'int passthrough'                   => [7, '7'],
            'decimal string trimmed'            => ['6240000001.000', '6240000001'],
            'decimal string leading zeros'      => ['00.50', '0.5'],
            'sci string expanded'               => ['1e-5', '0.00001'],
        ];
    }

    #[DataProvider('cases')]
    public function test_from_number(int|float|string $input, string $expected): void
    {
        $out = DecimalString::fromNumber($input);

        $this->assertSame($expected, $out);
        $this->assertDoesNotMatchRegularExpression('/[eE]/', $out);
    }

    public function test_non_finite_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DecimalString::fromNumber(INF);
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DecimalString::fromNumber(NAN);
    }

    public function test_non_numeric_string_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DecimalString::fromNumber('abc');
    }
}
