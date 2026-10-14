<?php

declare(strict_types=1);

namespace App\Support\Schema;

use App\Models\GridOrder;

/**
 * Every ENUM column the application writes, per table: what the code may
 * write must be exactly what the database allows.
 *
 * bot_configs and completed_trades have no ENUM column in any migration (their
 * status-like columns are VARCHAR / booleans); they are listed so
 * `schema:check-enums` still inspects them on the host and reports any ENUM a
 * manual change may have introduced there.
 */
final class EnumRegistry
{
    /** @return array<string, array<string, list<string>>> table => column => values */
    public static function expected(): array
    {
        return [
            'grid_orders'      => GridOrder::ENUM_COLUMNS,
            'bot_configs'      => [],
            'completed_trades' => [],
        ];
    }
}
