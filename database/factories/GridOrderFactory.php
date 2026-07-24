<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BotConfig;
use App\Models\GridOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 13 Step 5 — test factory for GridOrder.
 *
 * GridOrder does NOT use the HasFactory trait (production model, untouched this
 * step), so drive this factory directly:
 *
 *     GridOrderFactory::new()->buy()->placed()->create(['bot_config_id' => $bot->id]);
 *
 * not via GridOrder::factory(). The explicit $model property makes the direct
 * form resolve correctly.
 *
 * The defaults respect two guards on the model so a bare create() never
 * explodes and never needs the test to pre-fill half the row:
 *   - GridOrder::setPriceAttribute() casts price to a STRING (PARAM_STR binding
 *     for the DECIMAL(20,0) column). We supply an integer-valued price; the
 *     mutator stringifies it.
 *   - GridOrder::booted()::saving() rejects a non-numeric or <= 0 price and any
 *     price longer than 20 characters. The default 98_500_000_000 is positive,
 *     numeric and 11 digits — comfortably inside the DECIMAL(20,0) ceiling.
 *
 * @extends Factory<GridOrder>
 */
class GridOrderFactory extends Factory
{
    /** GridOrder has no HasFactory trait; bind the model explicitly. */
    protected $model = GridOrder::class;

    public function definition(): array
    {
        return [
            // grid_orders.bot_config_id is NOT nullable. Lazily create an owning
            // bot so a standalone GridOrderFactory::new()->create() works; tests
            // that already have a bot just override this key.
            'bot_config_id'   => fn () => BotConfigFactory::new()->create()->id,

            // DECIMAL(20,0) IRT price — passed as an int; the model's mutator
            // stringifies it for a safe PARAM_STR binding.
            'price'           => 98_500_000_000,

            // DECIMAL(20,8) BTC amount.
            'amount'          => '0.00100000',

            'type'            => 'buy',
            'status'          => 'placed',
            'role'            => 'initial_grid',

            'client_order_id' => fn () => 'grid-test-' . $this->faker->unique()->numerify('##########'),
            'nobitex_order_id'=> null,
            'paired_order_id' => null,
        ];
    }

    // ── Side ─────────────────────────────────────────────────────────────────

    public function buy(): static
    {
        return $this->state(fn () => ['type' => 'buy']);
    }

    public function sell(): static
    {
        return $this->state(fn () => ['type' => 'sell']);
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function placed(): static
    {
        return $this->state(fn () => ['status' => 'placed']);
    }

    public function filled(): static
    {
        return $this->state(fn (array $attrs) => [
            'status'        => 'filled',
            'filled_at'     => now(),
            'filled_amount' => $attrs['amount'] ?? '0.00100000',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    public function partiallyFilled(): static
    {
        return $this->state(function (array $attrs) {
            $amount = (string) ($attrs['amount'] ?? '0.00100000');

            return [
                'status'           => 'partially_filled',
                'filled_amount'    => bcdiv($amount, '2', 8),
                'remaining_amount' => bcsub($amount, bcdiv($amount, '2', 8), 8),
            ];
        });
    }

    public function submissionUnknown(): static
    {
        return $this->state(fn () => ['status' => 'submission_unknown']);
    }

    // ── Role ─────────────────────────────────────────────────────────────────

    public function initialGrid(): static
    {
        return $this->state(fn () => ['role' => 'initial_grid']);
    }

    public function cycleExit(): static
    {
        return $this->state(fn () => ['role' => 'cycle_exit']);
    }

    // ── Pair linkage helper ──────────────────────────────────────────────────

    /**
     * Bidirectionally link two already-persisted grid orders:
     * a.paired_order_id = b.id and b.paired_order_id = a.id.
     *
     * Both models must already carry a valid (positive, numeric) price, since
     * ->save() re-runs GridOrder::saving()'s positivity/length guard. Factory
     * defaults guarantee that, so a caller only ever passes freshly-created
     * orders here.
     */
    public static function linkPair(GridOrder $a, GridOrder $b): void
    {
        $a->paired_order_id = $b->id;
        $a->save();

        $b->paired_order_id = $a->id;
        $b->save();
    }
}
