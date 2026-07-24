<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BotConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 13 Step 5 — test factory for BotConfig (BTCIRT grid bot).
 *
 * BotConfig does NOT use the HasFactory trait (it is a production model this
 * step must not touch), so this factory is meant to be driven directly:
 *
 *     BotConfigFactory::new()->create();
 *     BotConfigFactory::new()->live()->stopped()->create([...]);
 *
 * not via BotConfig::factory(). The explicit $model property below is what
 * makes the direct form resolve the right model.
 *
 * Defaults are at realistic BTCIRT magnitude so a bare create() already
 * satisfies the model + observer + KillSwitch code paths without the test
 * overriding half the attributes:
 *   - grid_center_price ~98.5e9 IRT (the Kill Switch stop-loss anchor)
 *   - total_capital in the tens of millions of IRT
 *   - fee_bps 35 (0.35%), the exchange default
 *
 * @extends Factory<BotConfig>
 */
class BotConfigFactory extends Factory
{
    /** BotConfig has no HasFactory trait; bind the model explicitly. */
    protected $model = BotConfig::class;

    public function definition(): array
    {
        return [
            'name'                   => 'BTCIRT Grid ' . $this->faker->unique()->numberBetween(1, 100000),
            'user_id'                => null,
            'symbol'                 => 'BTCIRT',
            'mode'                   => 'both',
            'simulation'             => true,
            'is_active'              => true,

            // Grid geometry.
            'grid_levels'            => 10,
            'levels'                 => 10,
            'grid_spacing'           => 1.00,
            'step_pct'               => 0.250,
            'active_capital_percent' => 100.00,

            // Money columns at realistic BTCIRT magnitude. These are DECIMAL(20,0)
            // IRT columns in production; sqlite cannot hold them faithfully (see
            // BuildsGridSchema), but the magnitudes here stay well inside a 64-bit
            // integer so the no-database factory usage is unaffected.
            'total_capital'          => 50_000_000,      // 50M IRT
            'budget_irt'             => 50_000_000,
            'center_price'           => 98_500_000_000,  // ~98.5B IRT / BTC
            'grid_center_price'      => 98_500_000_000,  // stable Kill Switch anchor
            'fee_bps'                => 35,              // 0.35%

            // Risk management (Kill Switch reads these).
            'stop_loss_percent'      => 5.00,
            'take_profit_percent'    => null,
            'max_drawdown_percent'   => null,

            'init_status'            => 'running',
        ];
    }

    // ── Simulation / live ────────────────────────────────────────────────────

    public function simulation(): static
    {
        return $this->state(fn () => ['simulation' => true]);
    }

    public function live(): static
    {
        return $this->state(fn () => ['simulation' => false]);
    }

    // ── Active / stopped ─────────────────────────────────────────────────────

    public function active(): static
    {
        return $this->state(fn () => [
            'is_active'   => true,
            'started_at'  => now(),
            'stopped_at'  => null,
            'stop_reason' => null,
        ]);
    }

    public function stopped(): static
    {
        return $this->state(fn () => [
            'is_active'   => false,
            'started_at'  => now()->subHour(),
            'stopped_at'  => now(),
            'stop_reason' => 'manual_stop',
        ]);
    }

    // ── Kill-switch fields ───────────────────────────────────────────────────

    /**
     * Populate the Kill Switch inputs with sane, trippable thresholds and a
     * stable price anchor. KillSwitchService needs a positive total_capital,
     * a grid_center_price anchor, and at least one of stop_loss_percent /
     * max_drawdown_percent to do any work.
     */
    public function withKillSwitch(
        float $stopLossPercent = 5.0,
        float $maxDrawdownPercent = 10.0,
        int|string $gridCenterPrice = 98_500_000_000,
    ): static {
        return $this->state(fn () => [
            'stop_loss_percent'    => $stopLossPercent,
            'max_drawdown_percent' => $maxDrawdownPercent,
            'grid_center_price'    => $gridCenterPrice,
            'total_capital'        => 50_000_000,
        ]);
    }
}
