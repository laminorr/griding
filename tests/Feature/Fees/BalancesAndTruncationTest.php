<?php

declare(strict_types=1);

namespace Tests\Feature\Fees;

use App\Contracts\MarketData;
use App\DTOs\CreateOrderDto;
use App\Enums\ExecutionType;
use App\Enums\OrderSide;
use App\Services\GridPlanner;
use App\Services\NobitexService;
use App\Support\QtyPrecision;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Fee model Phase 2 — balance semantics (audit D9) and quantity truncation
 * (audit D10 / A.2).
 */
final class BalancesAndTruncationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.nobitex.base_url'        => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'         => '',
            'trading.nobitex.api_public_key'  => 'test-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES)), '+/', '-_'), '='),
            'trading.nobitex.retry.times'     => 1,
            'trading.nobitex.retry.sleep'     => 0,
            'trading.nobitex.rate_limit.rpm'  => 1000,
            'trading.exchange.precision.BTCIRT.qty_decimals'  => 8,
            'trading.exchange.precision.USDTIRT.qty_decimals' => 2,
        ]);
        // Reset the once-per-process fallback log guard.
        (new ReflectionProperty(NobitexService::class, 'balanceFallbackLogged'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** One wallet row exactly as /users/wallets/list returns it (V3 key set). */
    private static function wallet(string $currency, string $balance, string $blocked, ?string $active): array
    {
        $w = [
            'depositAddress' => null, 'depositTag' => null, 'depositInfo' => [],
            'id' => 1, 'currency' => $currency,
            'balance' => $balance, 'blockedBalance' => $blocked,
            'rialBalance' => 0, 'rialBalanceSell' => 0,
        ];
        if ($active !== null) {
            $w['activeBalance'] = $active;
        }
        return $w;
    }

    // ── getBalances ────────────────────────────────────────────────────────

    public function test_available_is_active_balance_locked_is_blocked_balance_total_is_balance(): void
    {
        Http::fake(['*/users/wallets/list' => Http::response(['status' => 'ok', 'wallets' => [
            // V4: the account after buying 0.0003 BTC (fee taken in BTC), nothing open.
            self::wallet('btc', '0.000299721', '0', '0.000299721'),
            // Rial with an open buy order holding 5,000,000.
            self::wallet('rls', '20000000', '5000000', '15000000'),
        ]], 200)]);

        $b = (new NobitexService)->getBalances();

        $this->assertSame(['available' => '0.000299721', 'locked' => '0', 'total' => '0.000299721'], $b['btc']);
        $this->assertSame(['available' => '15000000', 'locked' => '5000000', 'total' => '20000000'], $b['rls']);
    }

    /**
     * D9: with an open sell holding 0.001 BTC, the old code reported the TOTAL
     * (0.0013) as available. Now only the free 0.0003 is.
     */
    public function test_btc_locked_in_open_sells_is_not_reported_as_available(): void
    {
        Http::fake(['*/users/wallets/list' => Http::response(['status' => 'ok', 'wallets' => [
            self::wallet('btc', '0.0013', '0.001', '0.0003'),
        ]], 200)]);

        $b = (new NobitexService)->getBalances();

        $this->assertSame('0.0003', $b['btc']['available']);
        $this->assertSame('0.001', $b['btc']['locked']);
        $this->assertSame('0.0013', $b['btc']['total']);
    }

    public function test_missing_active_balance_falls_back_to_balance_minus_blocked_and_logs_once(): void
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('trading')->andReturn($logger);
        Log::shouldReceive('channel')->with('nobitex')->andReturn(Mockery::spy(LoggerInterface::class));

        Http::fake(['*/users/wallets/list' => Http::response(['status' => 'ok', 'wallets' => [
            self::wallet('btc', '0.0013', '0.001', null),
            self::wallet('rls', '100', '0', null),
        ]], 200)]);

        $svc = new NobitexService;
        $b   = $svc->getBalances();
        $svc->getBalances();

        $this->assertSame('0.0003', $b['btc']['available']);
        $this->assertSame('100', $b['rls']['available']);
        $logger->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'BALANCE_ACTIVE_FIELD_MISSING')->once();
    }

    // ── placeOrder truncation (exit-order path) ────────────────────────────

    public function test_place_order_truncates_12dp_amounts_down_never_up(): void
    {
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = json_decode($request->body(), true)['amount'] ?? null;
            return Http::response(['status' => 'ok', 'order' => ['id' => 1]], 200);
        });

        $svc = new NobitexService;
        $svc->placeOrder('BTCIRT', 'sell', 219240000000, '0.0057725724');      // audit C1 credited
        $svc->placeOrder('BTCIRT', 'sell', 219240000000, '0.000023092125');    // audit C5 small, 12 dp
        $svc->placeOrder('BTCIRT', 'sell', 219240000000, '0.000000019999999'); // just below 2 steps
        $svc->placeOrder('BTCIRT', 'sell', 219240000000, '0.00577257');        // already on step
        $svc->placeOrder('USDTIRT', 'buy', 1000000, '12.3456789');             // 2-dp market

        $this->assertSame(['0.00577257', '0.00002309', '0.00000001', '0.00577257', '12.34'], $sent);
    }

    public function test_place_order_refuses_an_amount_that_truncates_to_zero_without_sending(): void
    {
        Http::fake();

        try {
            (new NobitexService)->placeOrder('BTCIRT', 'sell', 219240000000, '0.000000009');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('truncates to zero', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    // ── CreateOrderDto (grid-order path) shares the helper ─────────────────

    public function test_create_order_dto_truncates_with_the_same_helper(): void
    {
        $dto = new CreateOrderDto(OrderSide::SELL, ExecutionType::LIMIT, 'btc', 'rls', '0.000023092125', 219240000000);
        $this->assertSame('0.00002309', $dto->toApiPayload()['amount']);

        // dst 'rls' must resolve to the IRT market's precision (USDTIRT = 2 dp),
        // not silently fall back to 8.
        $usdt = new CreateOrderDto(OrderSide::BUY, ExecutionType::LIMIT, 'usdt', 'rls', '12.3456789', 1000000);
        $this->assertSame('12.34', $usdt->toApiPayload()['amount']);

        $plain = new CreateOrderDto(OrderSide::BUY, ExecutionType::LIMIT, 'btc', 'irt', '0.0015000', 100);
        $this->assertSame('0.0015', $plain->toApiPayload()['amount']);
    }

    public function test_qty_precision_helper(): void
    {
        $this->assertSame('BTCIRT', QtyPrecision::canonicalSymbol('btc-rls'));
        $this->assertSame(8, QtyPrecision::decimalsFor('BTCIRT'));
        $this->assertSame('0.00000001', QtyPrecision::step('BTCIRT'));
        $this->assertSame('0.01', QtyPrecision::step('USDTIRT'));
        $this->assertSame('0.00580155', QtyPrecision::ceil('0.005801544611528822', 'BTCIRT')); // audit C2 restore
        $this->assertSame('0.00580154', QtyPrecision::floor('0.005801544611528822', 'BTCIRT'));
    }

    // ── GridPlanner::formatQty truncates (audit A.2) ───────────────────────

    /**
     * 0.00000005 BTC held, split over 2 sells = 0.000000025 each. The old
     * half-up rounding planned 0.00000003 per sell (Σ 0.00000006 > held);
     * truncation plans 0.00000002 (Σ 0.00000004 <= held).
     */
    public function test_planner_preset_split_truncates_and_never_exceeds_holdings(): void
    {
        config(['trading.min_order_value_irt' => 3_000_000, 'trading.ticks.BTCIRT' => 10]);
        $planner = new GridPlanner(Mockery::mock(MarketData::class));

        $plan = $planner->plan('BTCIRT', 100_000, 4, 1.0, 'both', fixedQty: '0.001', tick: 10, presetBaseQty: '0.00000005');

        $sells = array_values(array_filter($plan['items'], fn ($i) => $i['side'] === 'sell'));
        $this->assertCount(2, $sells);
        foreach ($sells as $s) {
            $this->assertSame('0.00000002', $s['quantity']);
        }
        $this->assertSame('0.00000002', $plan['preset_sell_qty']);
    }

    /**
     * Budget sizing truncates too: 10,000,000 / 3,000,007 = 3.3333255555737…
     * → floor8 = 3.33332555 (the old half-up rounding gave 3.33332556).
     */
    public function test_planner_budget_quantity_is_truncated(): void
    {
        config(['trading.min_order_value_irt' => 1, 'trading.ticks.BTCIRT' => 1]);
        $planner = new GridPlanner(Mockery::mock(MarketData::class));

        // 1 buy level only (mode buy, levels 1), step 0.0001% → price floor(3,000,010 × 0.999999) = 3,000,007
        $plan = $planner->plan('BTCIRT', 3_000_010, 1, 0.0001, 'buy', 10_000_000, tick: 1);

        $item = $plan['items'][0];
        $this->assertSame(3_000_007, $item['price']);
        $this->assertSame('3.33332555', $item['quantity']);
    }
}
