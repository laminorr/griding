<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fee model Phase 1 — Money::floorToScale / ceilToScale are pure bcmath
 * (no float round-trip), exact at 12+ dp and at IRT magnitudes.
 */
final class MoneyFloorCeilTest extends TestCase
{
    /** @return array<string,array{0:string,1:int,2:string,3:string}> value, scale, floor, ceil */
    public static function cases(): array
    {
        return [
            'credited small (audit C5)'    => ['0.000023092125', 8, '0.00002309', '0.0000231'],
            'credited large (audit C5)'    => ['0.0057725724', 8, '0.00577257', '0.00577258'],
            'already on step'              => ['0.00578704', 8, '0.00578704', '0.00578704'],
            'integer'                      => ['5', 8, '5', '5'],
            'tiny positive'                => ['0.000000000001', 8, '0', '0.00000001'],
            'negative remainder'           => ['-0.0000000024', 8, '-0.00000001', '0'],
            'negative on step'             => ['-0.00000002', 8, '-0.00000002', '-0.00000002'],
            'IRT magnitude, scale 0'       => ['12453131.376', 0, '12453131', '12453132'],
            'huge, beyond float precision' => ['123456789012345678.999999999999', 0, '123456789012345678', '123456789012345679'],
            '25 fractional digits'         => ['0.0000000000000000000000001', 20, '0', '0.00000000000000000001'],
        ];
    }

    #[DataProvider('cases')]
    public function test_floor_and_ceil(string $value, int $scale, string $floor, string $ceil): void
    {
        $this->assertSame($floor, Money::floorToScale($value, $scale));
        $this->assertSame($ceil, Money::ceilToScale($value, $scale));
    }

    public function test_negative_scale_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::floorToScale('1', -1);
    }
}
