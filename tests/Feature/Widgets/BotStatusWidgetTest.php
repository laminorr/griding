<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Filament\Widgets\BotStatusWidget;
use Database\Factories\BotConfigFactory;
use Database\Factories\GridOrderFactory;
use Filament\Widgets\StatsOverviewWidget\Stat;
use ReflectionMethod;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Panel Phase P3 — Fix 1 regression coverage for the Dashboard's BotStatusWidget.
 *
 * The dashboard tiles must read System A (bot_configs + engine tables), not a
 * hardcoded "0 of 0 / 0 IRT". With one active bot holding capital and open
 * grid_orders, the counts have to come out non-zero. This test also pins the
 * P3 scoping fix on the "active orders" tile: only `placed` orders belonging to
 * an ACTIVE bot count — a paused bot's leftover placed orders must be excluded.
 */
final class BotStatusWidgetTest extends TestCase
{
    use BuildsGridSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    /** @return array<string, Stat> label => Stat */
    private function statsByLabel(): array
    {
        $method = new ReflectionMethod(BotStatusWidget::class, 'getStats');
        $method->setAccessible(true);

        $stats = $method->invoke(new BotStatusWidget());

        $byLabel = [];
        foreach ($stats as $stat) {
            $byLabel[$stat->getLabel()] = $stat;
        }

        return $byLabel;
    }

    public function test_tiles_reflect_active_bot_and_open_orders(): void
    {
        // One ACTIVE bot with real capital.
        $active = BotConfigFactory::new()->create([
            'is_active'              => true,
            'total_capital'          => 50_000_000,
            'active_capital_percent' => 80,
        ]);

        // A second, PAUSED bot with its own capital and leftover placed orders.
        $paused = BotConfigFactory::new()->create([
            'is_active'              => false,
            'total_capital'          => 20_000_000,
            'active_capital_percent' => 100,
        ]);

        // Three open (placed) orders on the active bot ...
        GridOrderFactory::new()->count(3)->placed()->create(['bot_config_id' => $active->id]);
        // ... and two on the paused bot that must NOT be counted as active orders.
        GridOrderFactory::new()->count(2)->placed()->create(['bot_config_id' => $paused->id]);

        $stats = $this->statsByLabel();

        // Bots tile: 1 active of 2 total (reads bot_configs, System A).
        $this->assertStringContainsString('1 از 2', (string) $stats['وضعیت ربات‌ها']->getValue());

        // Capital tile: total = 70M, active = 40M — neither hardcoded to zero.
        $capitalValue = (string) $stats['سرمایه کل']->getValue();
        $this->assertStringContainsString('70,000,000', $capitalValue);
        $this->assertStringContainsString('40,000,000', (string) $stats['سرمایه کل']->getDescription());

        // Active-orders tile: only the active bot's 3 placed orders count.
        $this->assertSame('3', (string) $stats['سفارشات فعال']->getValue());
    }
}
