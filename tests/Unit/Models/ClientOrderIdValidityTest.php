<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Exceptions\DefinitiveInvalidArgumentRejection;
use App\Exceptions\DefinitiveOrderRejection;
use App\Models\GridOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documented Nobitex constraint on clientOrderId (at most 32 chars,
 * [A-Za-z0-9-] only) and the v2 id format that always satisfies it.
 *
 * GridOrder::isValidNobitexClientOrderId() is the single source of truth for
 * the constraint; GridOrder::clientOrderIdFor() is the single place the format
 * "g{botId}-{gridOrderRowId}" lives. The legacy price-derived scheme
 * ('grid:{bot}:{SYMBOL}:{side}:{price}') violated both rules and was removed.
 *
 * Pure PHP (no DB / framework boot) — extends PHPUnit's bare TestCase. Rows
 * are unsaved models with their keys filled in by hand.
 */
final class ClientOrderIdValidityTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function validIds(): array
    {
        return [
            'letters'                 => ['grid'],
            'digits'                  => ['12345'],
            'hyphen-separated'        => ['grid-1-BTCIRT-buy-100000000'],
            'mixed'                   => ['g1B-buy-abc123'],
            'v2 format'               => ['g47-123456'],
            'exactly 32 chars'        => [str_repeat('a', 32)],
        ];
    }

    #[DataProvider('validIds')]
    public function test_accepts_compliant_ids(string $id): void
    {
        $this->assertTrue(GridOrder::isValidNobitexClientOrderId($id));
    }

    /** @return array<string,array{0:string}> */
    public static function invalidIds(): array
    {
        return [
            'empty'               => [''],
            'contains colon'      => ['grid:1:BTCIRT:buy:100000000'],
            'legacy 33-char id'   => ['grid:47:BTCIRT:sell:216389999960'],
            'contains slash'      => ['grid/1'],
            'contains underscore' => ['grid_1'],
            'contains space'      => ['grid 1'],
            'contains dot'        => ['grid.1'],
            'trailing newline'    => ["g47-1\n"],
            'leading newline'     => ["\ng47-1"],
            'non-ascii digit'     => ['g47-١٢٣'],
            '33 chars'            => [str_repeat('a', 33)],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_rejects_non_compliant_ids(string $id): void
    {
        $this->assertFalse(GridOrder::isValidNobitexClientOrderId($id));
    }

    private function row(mixed $botId, mixed $rowId): GridOrder
    {
        $row = new GridOrder();
        $row->forceFill(['id' => $rowId, 'bot_config_id' => $botId]);

        return $row;
    }

    public function test_format_for_small_ids(): void
    {
        $this->assertSame('g1-1', GridOrder::clientOrderIdFor($this->row(1, 1)));
        $this->assertSame('g47-123456', GridOrder::clientOrderIdFor($this->row(47, 123456)));
        // Drivers may hand back numeric strings.
        $this->assertSame('g47-123456', GridOrder::clientOrderIdFor($this->row('47', '123456')));
    }

    public function test_format_for_huge_ids_stays_within_32_chars(): void
    {
        $cases = [
            [999_999, PHP_INT_MAX],                       // 6-digit bot, 19-digit row
            ['999999', '99999999999999999999'],           // 6-digit bot, 20-digit row
        ];

        foreach ($cases as [$bot, $id]) {
            $cid = GridOrder::clientOrderIdFor($this->row($bot, $id));
            $this->assertSame('g' . $bot . '-' . $id, $cid);
            $this->assertLessThanOrEqual(32, strlen($cid));
            $this->assertTrue(GridOrder::isValidNobitexClientOrderId($cid));
        }

        $this->assertSame(28, strlen(GridOrder::clientOrderIdFor($this->row('999999', '99999999999999999999'))));
    }

    public function test_generated_ids_are_always_valid_and_distinct_per_row(): void
    {
        $seen = [];
        mt_srand(4242);
        for ($i = 0; $i < 2000; $i++) {
            $bot = mt_rand(1, 999_999);
            $id  = mt_rand(1, PHP_INT_MAX);
            $cid = GridOrder::clientOrderIdFor($this->row($bot, $id));

            $this->assertTrue(GridOrder::isValidNobitexClientOrderId($cid), $cid);
            $this->assertLessThanOrEqual(32, strlen($cid));
            $seen[$cid] = true;
        }
        $this->assertCount(2000, $seen);

        // Same bot, different rows → different ids (the property the old
        // price-derived scheme lacked).
        $this->assertNotSame(
            GridOrder::clientOrderIdFor($this->row(47, 1)),
            GridOrder::clientOrderIdFor($this->row(47, 2))
        );
    }

    /** @return array<string,array{0:mixed,1:mixed}> */
    public static function unusableRows(): array
    {
        return [
            'unsaved row (no id)' => [47, null],
            'no bot'              => [null, 5],
            'zero id'             => [47, 0],
            'negative bot'        => [-1, 5],
            'non-numeric id'      => [47, 'abc'],
            // Unrealistic ids whose result would exceed 32 chars: the
            // generation-time assertion must refuse them.
            'id overflow'         => ['1234567890123', '99999999999999999999'],
        ];
    }

    #[DataProvider('unusableRows')]
    public function test_refuses_rows_it_cannot_identify_with_local_validation(mixed $bot, mixed $id): void
    {
        try {
            GridOrder::clientOrderIdFor($this->row($bot, $id));
            $this->fail('clientOrderIdFor must refuse this row.');
        } catch (DefinitiveInvalidArgumentRejection $e) {
            $this->assertInstanceOf(DefinitiveOrderRejection::class, $e);
            $this->assertSame('LocalValidation', $e->errorCode());
        }
    }
}
