<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\Schema\EnumColumns;
use Tests\TestCase;

/** `schema:check-enums` against a fake information_schema (read-only by construction). */
final class SchemaCheckEnumsTest extends TestCase
{
    private const LIVE_OK = [
        'grid_orders' => [
            'type'   => "enum('buy','sell')",
            'status' => "enum('pending','placed','filled','cancelled','failed','partially_filled','submission_unknown')",
            'role'   => "enum('initial_grid','cycle_exit','rebalance','manual','rearm')",
        ],
    ];

    /** @param array<string, array<string, string>> $live table => column => COLUMN_TYPE */
    private function live(array $live, string $driver = 'mysql'): object
    {
        $fake = new class($live, $driver) extends EnumColumns {
            public array $statements = [];

            public function __construct(private array $live, private string $drv) {}

            protected function driver(): string
            {
                return $this->drv;
            }

            protected function select(string $sql, array $bindings): array
            {
                $out = [];
                foreach ($this->live[$bindings[0]] ?? [] as $col => $type) {
                    $out[] = (object) ['COLUMN_NAME' => $col, 'COLUMN_TYPE' => $type];
                }
                return $out;
            }

            protected function statement(string $sql): void
            {
                $this->statements[] = $sql;
            }
        };
        $this->app->instance(EnumColumns::class, $fake);

        return $fake;
    }

    public function test_all_ok(): void
    {
        $fake = $this->live(self::LIVE_OK);
        $this->artisan('schema:check-enums')
            ->expectsOutputToContain('All ENUM columns accept every value the code writes.')
            ->assertExitCode(0);
        $this->assertSame([], $fake->statements);
    }

    public function test_the_production_state_is_missing_rearm_and_fails(): void
    {
        $live = self::LIVE_OK;
        $live['grid_orders']['role'] = "enum('initial_grid','cycle_exit','rebalance','manual')";
        $fake = $this->live($live);

        $this->artisan('schema:check-enums')
            ->expectsOutputToContain('missing: rearm')
            ->expectsOutputToContain('ENUM drift')
            ->assertExitCode(1);
        $this->assertSame([], $fake->statements);
    }

    public function test_extra_and_untracked_are_reported_but_do_not_fail(): void
    {
        $live = self::LIVE_OK;
        $live['grid_orders']['type'] = "enum('buy','sell','legacy')";
        $live['bot_configs'] = ['mode' => "enum('a','b')"];
        $this->live($live);

        $this->artisan('schema:check-enums')
            ->expectsOutputToContain('extra: legacy')
            ->expectsOutputToContain('UNTRACKED')
            ->assertExitCode(0);
    }

    public function test_a_column_that_is_not_an_enum_fails(): void
    {
        $live = self::LIVE_OK;
        unset($live['grid_orders']['status']);
        $this->live($live);

        $this->artisan('schema:check-enums')->expectsOutputToContain('ABSENT')->assertExitCode(1);
    }

    public function test_sqlite_has_nothing_to_check(): void
    {
        $this->live([], 'sqlite');
        $this->artisan('schema:check-enums')->expectsOutputToContain('not mysql/mariadb')->assertExitCode(0);
    }
}
