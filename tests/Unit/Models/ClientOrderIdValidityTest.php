<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GridOrder;
use PHPUnit\Framework\TestCase;

/**
 * Order-lifecycle hardening (defensive) — the documented Nobitex constraint on
 * clientOrderId: at most 32 chars and matching ^[A-Za-z0-9-]+$.
 *
 * GridOrder::isValidNobitexClientOrderId() is the single source of truth for
 * that constraint. These tests lock its semantics so a FUTURE change to the id
 * scheme can be asserted against both constraints instead of silently producing
 * ids the exchange rejects.
 *
 * They ALSO document the current-state gap: the live
 * buildClientOrderId('grid:{bot}:{SYMBOL}:{side}:{price}') scheme does NOT yet
 * satisfy the constraint (':' is outside the allowed class, and the IRT price is
 * unbounded), and that exact wire format is deliberately pinned by existing
 * placement tests. Tightening the generator changes the clientOrderId actually
 * sent on placement — a separately-reviewed change, out of scope for this
 * lifecycle-accounting task. This test captures the gap so any future scheme
 * change is prompted to make the generator compliant.
 *
 * Pure PHP (no DB / framework boot) — extends PHPUnit's bare TestCase.
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
            'exactly 32 chars'        => [str_repeat('a', 32)],
        ];
    }

    /**
     * @dataProvider validIds
     */
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
            'contains slash'      => ['grid/1'],
            'contains underscore' => ['grid_1'],
            'contains space'      => ['grid 1'],
            'contains dot'        => ['grid.1'],
            '33 chars'            => [str_repeat('a', 33)],
        ];
    }

    /**
     * @dataProvider invalidIds
     */
    public function test_rejects_non_compliant_ids(string $id): void
    {
        $this->assertFalse(GridOrder::isValidNobitexClientOrderId($id));
    }

    /**
     * DOCUMENTED GAP (see class docblock): the current generator's output does
     * NOT satisfy the Nobitex constraint today — it uses ':' separators. This
     * assertion captures that reality; the day the scheme is made compliant, it
     * flips and must be updated to assertTrue, forcing the generator and the
     * documented invariant to move together.
     */
    public function test_current_generator_output_is_not_yet_nobitex_compliant(): void
    {
        $id = GridOrder::buildClientOrderId(1, 'BTCIRT', 'buy', 100_000_000);

        $this->assertStringContainsString(':', $id, 'Current scheme is colon-separated.');
        $this->assertFalse(
            GridOrder::isValidNobitexClientOrderId($id),
            'Current buildClientOrderId output is known to violate the documented Nobitex constraint (colons / unbounded length).'
        );
    }
}
