<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\BotConfigResource\Pages\ListBotConfigs;
use Database\Factories\BotConfigFactory;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * Phase P3.5 Fix 1 — the "30 minutes not updated" alarm checked the wrong column.
 *
 * checkActiveBotsHealth() counted active bots whose `updated_at` was older than
 * 30 minutes. But `updated_at` only changes when the bot RECORD is edited, not
 * when the bot is checked — so a bot created hours ago yet checked every minute
 * always looked "stale" and false-alarmed. The correct column is `last_check_at`,
 * which CheckTradesJob writes every minute.
 *
 * The count now lives in getStaleActiveBotsCount(). These tests assert:
 *   - a recent last_check_at does NOT count as stale,
 *   - an old last_check_at DOES count,
 *   - a NULL last_check_at (never checked / brand-new) never counts,
 *   - inactive bots are ignored regardless of last_check_at.
 */
final class ListBotConfigsStaleAlarmTest extends TestCase
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

    private function staleCount(): int
    {
        return (new ListBotConfigs())->getStaleActiveBotsCount();
    }

    public function test_recent_last_check_at_is_not_stale(): void
    {
        // Bot created long ago (old updated_at) but checked one minute ago.
        // Under the old updated_at query this would have false-alarmed.
        BotConfigFactory::new()->create([
            'is_active'     => true,
            'last_check_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, $this->staleCount());
    }

    public function test_old_last_check_at_is_stale(): void
    {
        BotConfigFactory::new()->create([
            'is_active'     => true,
            'last_check_at' => now()->subMinutes(45),
        ]);

        $this->assertSame(1, $this->staleCount());
    }

    public function test_null_last_check_at_never_counts(): void
    {
        // A brand-new active bot that has never had a check cycle must not alarm.
        BotConfigFactory::new()->create([
            'is_active'     => true,
            'last_check_at' => null,
        ]);

        $this->assertSame(0, $this->staleCount());
    }

    public function test_inactive_bots_are_ignored(): void
    {
        BotConfigFactory::new()->create([
            'is_active'     => false,
            'last_check_at' => now()->subHours(3),
        ]);

        $this->assertSame(0, $this->staleCount());
    }
}
