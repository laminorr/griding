<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\BotConfig;
use App\Models\GridOrder;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * grid:sync-precision — repair rows placed before market precision was
 * enforced (bot 48). Exchange reads are faked; the command never places,
 * cancels or replaces orders.
 */
final class GridSyncPrecisionTest extends TestCase
{
    use BuildsGridSchema;

    private BotConfig $bot;

    /** @var array<string,array<string,mixed>> nobitex id → order payload */
    private array $exchange = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGridSchema();

        $seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        config([
            'trading.exchange.precision_live' => false,
            'trading.nobitex.base_url' => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_public_key' => 'my-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            'trading.nobitex.retry.times' => 1,
            'trading.nobitex.retry.sleep' => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
        ]);

        $this->bot = BotConfig::create([
            'name' => 'bot48', 'symbol' => 'BTCIRT', 'simulation' => false, 'is_active' => true, 'grid_spacing' => 1.50,
        ]);

        // Bot 48 as observed: three grid sells sent at 0.00004504 (exchange
        // kept 0.000045) and exit buy 279 at …136 / 0.00004512 (exchange kept
        // …140 / 0.000045). Plus a FILLED row that also disagrees and must
        // never be touched.
        $this->row(275, 'sell', '228711000010', '0.00004504', 'placed', '9000275', '228711000010', '0.000045');
        $this->row(276, 'sell', '232141000010', '0.00004504', 'placed', '9000276', '232141000010', '0.000045');
        $this->row(277, 'sell', '235623000010', '0.00004504', 'partially_filled', '9000277', '235623000010', '0.000045');
        $this->row(279, 'buy', '221949050136', '0.00004512', 'placed', '9000279', '221949050140', '0.000045');
        $this->row(280, 'buy', '220000000006', '0.00004504', 'filled', '9000280', '220000000010', '0.000045');
        $this->row(281, 'buy', '219000000000', '0.00004504', 'placed', 'SIM-abc', '219000000000', '0.000045');

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/market/orders/status')) {
                $id = (string) (json_decode($request->body(), true)['id'] ?? '');
                return isset($this->exchange[$id])
                    ? Http::response(['status' => 'ok', 'order' => $this->exchange[$id]], 200)
                    : Http::response(['status' => 'failed', 'code' => 'NotFound'], 404);
            }
            // Anything else (add / cancel / update-status) must never be called.
            return Http::response(['status' => 'failed', 'message' => 'unexpected call'], 500);
        });
    }

    protected function tearDown(): void
    {
        $this->dropGridSchema();
        parent::tearDown();
    }

    private function row(int $id, string $side, string $price, string $amount, string $status, string $nid, string $exPrice, string $exAmount): void
    {
        (new GridOrder)->forceFill([
            'id' => $id, 'bot_config_id' => $this->bot->id, 'type' => $side, 'price' => $price,
            'amount' => $amount, 'original_amount' => $amount, 'status' => $status,
            'nobitex_order_id' => $nid, 'client_order_id' => "g48-{$id}",
            'filled_amount' => $status === 'filled' ? $amount : null,
        ])->save();
        $this->exchange[$nid] = [
            'id' => (int) preg_replace('/\D/', '', $nid) ?: 1, 'type' => $side, 'execution' => 'Limit',
            'status' => $status === 'filled' ? 'Done' : 'Active',
            'price' => $exPrice, 'amount' => $exAmount, 'matchedAmount' => '0',
        ];
    }

    private static function d($v): string
    {
        return Money::trimZeros((string) $v);
    }

    /** @return array<int,array{0:string,1:string}> id → [amount, price] */
    private function snapshot(): array
    {
        return GridOrder::orderBy('id')->get()
            ->mapWithKeys(fn (GridOrder $o) => [$o->id => [self::d($o->amount), self::d($o->price), (string) $o->status, self::d($o->filled_amount)]])
            ->all();
    }

    public function test_dry_run_prints_the_diff_and_changes_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan('grid:sync-precision', ['botId' => $this->bot->id])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('0.00004504')
            ->expectsOutputToContain('221949050140')
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
        Http::assertNotSent(fn ($r) => ! str_contains($r->url(), '/market/orders/status'));
    }

    public function test_apply_updates_only_open_rows_to_the_exchange_values(): void
    {
        $before = $this->snapshot();

        $this->artisan('grid:sync-precision', ['botId' => $this->bot->id, '--apply' => true])
            ->expectsOutputToContain('APPLIED')
            ->assertExitCode(0);

        $after = $this->snapshot();

        // 275/276/277 amount → 0.000045 (prices were already on tick)
        foreach ([275, 276, 277] as $id) {
            $this->assertSame('0.000045', $after[$id][0], "row {$id} amount");
            $this->assertSame($before[$id][1], $after[$id][1], "row {$id} price unchanged");
            $this->assertSame($before[$id][2], $after[$id][2], "row {$id} status unchanged");
        }
        // 279 amount → 0.000045, price → 221949050140; stays in place (placed)
        $this->assertSame(['0.000045', '221949050140', 'placed', $before[279][3]], $after[279]);

        // FILLED and SIM rows untouched
        $this->assertSame($before[280], $after[280]);
        $this->assertSame($before[281], $after[281]);

        // never cancels / replaces / places — only status reads
        Http::assertNotSent(fn ($r) => ! str_contains($r->url(), '/market/orders/status'));
        Http::assertNotSent(fn ($r) => str_contains($r->body(), '9000280') || str_contains($r->body(), 'SIM-abc'));
    }

    public function test_apply_is_idempotent(): void
    {
        $this->artisan('grid:sync-precision', ['botId' => $this->bot->id, '--apply' => true])->assertExitCode(0);
        $once = $this->snapshot();

        $this->artisan('grid:sync-precision', ['botId' => $this->bot->id, '--apply' => true])
            ->expectsOutputToContain('0 differ')
            ->assertExitCode(0);
        $this->assertSame($once, $this->snapshot());
    }

    public function test_unknown_bot_fails_cleanly(): void
    {
        $this->artisan('grid:sync-precision', ['botId' => 999])->assertExitCode(1);
    }
}
