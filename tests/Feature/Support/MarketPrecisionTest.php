<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\BotConfig;
use App\Services\NobitexService;
use App\Support\MarketPrecision;
use App\Support\QtyPrecision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsGridSchema;
use Tests\TestCase;

/**
 * MarketPrecision — live /v2/options → last-known-good → config, never throws.
 * The live payload below is the one verified on the host (bot-48 incident,
 * docs/market-precision.md). All HTTP is faked.
 */
final class MarketPrecisionTest extends TestCase
{
    use BuildsGridSchema;

    private const OPTIONS = [
        'status'  => 'ok',
        'nobitex' => [
            'amountPrecisions' => ['BTCIRT' => '0.000001', 'BTCUSDT' => '0.000001', 'ETHIRT' => '0.00001', 'USDTIRT' => '0.01'],
            'pricePrecisions'  => ['BTCIRT' => '10', 'BTCUSDT' => '0.01', 'ETHIRT' => '10', 'USDTIRT' => '10'],
            'minOrders'        => [],
        ],
    ];

    private LoggerInterface $log;

    protected function setUp(): void
    {
        parent::setUp();

        $seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        config([
            'trading.exchange.precision_live' => true,
            'trading.nobitex.base_url' => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_public_key' => 'my-public-key',
            'trading.nobitex.api_private_key' => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            'trading.nobitex.retry.times' => 1,
            'trading.nobitex.retry.sleep' => 0,
            'trading.nobitex.rate_limit.rpm' => 1000,
            // Deliberately different from live, to tell the sources apart.
            'trading.exchange.precision.BTCIRT.qty_decimals' => 8,
            'trading.exchange.precision.ETHIRT.qty_decimals' => 6,
            'trading.exchange.precision.USDTIRT.qty_decimals' => 2,
            'trading.ticks.BTCIRT' => 10,
        ]);
        Cache::flush();
        MarketPrecision::forgetLive();

        $this->log = Mockery::spy(LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->andReturnUsing(
            fn ($c) => $c === 'trading' ? $this->log : Mockery::spy(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeOptions(array $body = self::OPTIONS, int $status = 200): void
    {
        Http::fake(['*/v2/options*' => Http::response($body, $status)]);
    }

    // ── parsing ──────────────────────────────────────────────────────────

    public function test_parses_the_live_values(): void
    {
        $this->fakeOptions();

        $this->assertSame(6, MarketPrecision::qtyDecimals('BTCIRT'));
        $this->assertSame(10, MarketPrecision::priceTick('BTCIRT'));
        $this->assertSame(5, MarketPrecision::qtyDecimals('ETHIRT'));
        $this->assertSame(2, MarketPrecision::qtyDecimals('USDTIRT'));
        $this->assertSame(10, MarketPrecision::priceTick('USDTIRT'));

        // canonical symbols: private-endpoint spelling and dashed form
        $this->assertSame(6, MarketPrecision::qtyDecimals('BTCRLS'));
        $this->assertSame(6, MarketPrecision::qtyDecimals('btc-irt'));

        // QtyPrecision delegates; its floor/ceil API is unchanged
        $this->assertSame(6, QtyPrecision::decimalsFor('BTCIRT'));
        $this->assertSame('0.000045', QtyPrecision::floor('0.00004504', 'BTCIRT'));
        $this->assertSame('0.000046', QtyPrecision::ceil('0.00004512', 'BTCIRT'));
        $this->assertSame('0.000001', QtyPrecision::step('BTCIRT'));

        // the parsed map is cached (6h): one HTTP call for all of the above
        Http::assertSentCount(1);
    }

    /** @return array<string,array{0:mixed,1:?int}> */
    public static function qtySteps(): array
    {
        return [
            '6dp'        => ['0.000001', 6],
            '5dp'        => ['0.00001', 5],
            '2dp'        => ['0.01', 2],
            'trailing 0' => ['0.0000010', 6],
            'one'        => ['1', 0],
            'int one'    => [1, 0],
            'not pow10'  => ['0.0005', null],
            'ten'        => ['10', null],
            'float'      => [0.000001, null],
            'garbage'    => ['abc', null],
            'negative'   => ['-0.01', null],
        ];
    }

    #[DataProvider('qtySteps')]
    public function test_parse_qty_step(mixed $step, ?int $expected): void
    {
        $this->assertSame($expected, MarketPrecision::parseQtyStep($step));
    }

    public function test_parse_tick(): void
    {
        $this->assertSame(10, MarketPrecision::parseTick('10'));
        $this->assertSame(10, MarketPrecision::parseTick('10.00'));
        $this->assertSame(1, MarketPrecision::parseTick(1));
        $this->assertNull(MarketPrecision::parseTick('0.01'));
        $this->assertNull(MarketPrecision::parseTick('0'));
        $this->assertNull(MarketPrecision::parseTick(10.0));
    }

    // ── fallback chain ───────────────────────────────────────────────────

    public function test_options_failure_with_no_history_falls_back_to_config_and_never_throws(): void
    {
        $this->fakeOptions(['status' => 'failed'], 500);

        $this->assertSame(8, MarketPrecision::qtyDecimals('BTCIRT'));  // config
        $this->assertSame(10, MarketPrecision::priceTick('BTCIRT'));   // config
        $this->log->shouldHaveReceived('warning')->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_FALLBACK' && ($ctx['source'] ?? null) === 'config');
    }

    public function test_service_exception_never_escapes(): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOptionsV2')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(NobitexService::class, $svc);

        $this->assertSame(8, MarketPrecision::qtyDecimals('BTCIRT'));
        $this->assertSame(10, MarketPrecision::priceTick('BTCIRT'));
        $this->assertSame(221949050130, MarketPrecision::roundPrice('221949050136.35', 'BTCIRT', 'buy'));
    }

    public function test_failed_fetch_backs_off_instead_of_hammering_the_exchange(): void
    {
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOptionsV2')->once()->andThrow(new \RuntimeException('down'));
        $this->app->instance(NobitexService::class, $svc);

        for ($i = 0; $i < 5; $i++) {
            MarketPrecision::qtyDecimals('BTCIRT');
        }
        $this->addToAssertionCount(1); // ->once() is the assertion
    }

    public function test_unparsable_payload_falls_back(): void
    {
        $this->fakeOptions(['status' => 'ok', 'nobitex' => ['amountPrecisions' => 'nope']]);

        $this->assertSame(8, MarketPrecision::qtyDecimals('BTCIRT'));
        $this->log->shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'PRECISION_FALLBACK');
    }

    public function test_outage_after_a_good_fetch_uses_last_known_good(): void
    {
        $this->fakeOptions();
        $this->assertSame(6, MarketPrecision::qtyDecimals('BTCIRT'));

        // The 6h live entry (and the service's own 300s cache) expire, and
        // the exchange is now down.
        MarketPrecision::forgetLive();
        Cache::forget('nobitex:options:v2');
        $svc = Mockery::mock(NobitexService::class);
        $svc->shouldReceive('getOptionsV2')->andThrow(new \RuntimeException('down'));
        $this->app->instance(NobitexService::class, $svc);

        $this->assertSame(6, MarketPrecision::qtyDecimals('BTCIRT'));   // LKG, not config 8
        $this->assertSame(5, MarketPrecision::qtyDecimals('ETHIRT'));   // LKG, not config 6
        $this->assertSame(10, MarketPrecision::priceTick('BTCIRT'));
        $this->log->shouldHaveReceived('warning')->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_FALLBACK' && ($ctx['source'] ?? null) === 'last_known_good');
    }

    public function test_symbol_missing_from_live_uses_config(): void
    {
        $this->fakeOptions();
        config(['trading.exchange.precision.LTCIRT.qty_decimals' => 6, 'trading.ticks.LTCIRT' => 100]);

        $this->assertSame(6, MarketPrecision::qtyDecimals('LTCIRT'));
        $this->assertSame(100, MarketPrecision::priceTick('LTCIRT'));
    }

    public function test_live_disabled_uses_config_without_any_http(): void
    {
        config(['trading.exchange.precision_live' => false]);
        Http::fake();

        $this->assertSame(8, MarketPrecision::qtyDecimals('BTCIRT'));
        Http::assertNothingSent();
    }

    // ── drift / invalid ──────────────────────────────────────────────────

    public function test_drift_is_logged_once_per_symbol_per_day(): void
    {
        $this->fakeOptions(); // live 6 vs config 8

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(6, MarketPrecision::qtyDecimals('BTCIRT'));
        }

        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_DRIFT' && ($ctx['symbol'] ?? null) === 'BTCIRT' && ($ctx['field'] ?? null) === 'qty_decimals')
            ->once();
        // ticks agree (10 == 10) → no tick drift
        MarketPrecision::priceTick('BTCIRT');
        $this->log->shouldNotHaveReceived('warning', fn ($m, $ctx = []) => $m === 'PRECISION_DRIFT' && ($ctx['field'] ?? null) === 'tick');
    }

    public function test_non_integer_irt_tick_is_rejected(): void
    {
        $body = self::OPTIONS;
        $body['nobitex']['pricePrecisions']['BTCIRT'] = '0.5';
        $this->fakeOptions($body);

        $this->assertSame(10, MarketPrecision::priceTick('BTCIRT')); // config, not 0.5
        $this->assertSame(6, MarketPrecision::qtyDecimals('BTCIRT')); // the rest of the payload is still used
        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_INVALID' && ($ctx['symbol'] ?? null) === 'BTCIRT')
            ->once();
        // BTCUSDT's 0.01 tick is legitimate for a USDT market — not flagged
        $this->log->shouldNotHaveReceived('warning', fn ($m, $ctx = []) => $m === 'PRECISION_INVALID' && ($ctx['symbol'] ?? null) === 'BTCUSDT');
    }

    // ── log scope ────────────────────────────────────────────────────────

    public function test_invalid_tick_on_a_symbol_no_bot_trades_is_not_warned(): void
    {
        // TRADING_SYMBOLS_ALLOWED is BTCIRT,ETHIRT,USDTIRT; no bot_configs table here.
        $body = self::OPTIONS;
        $body['nobitex']['pricePrecisions']['1KBONKIRT'] = '0.001';
        $body['nobitex']['pricePrecisions']['PUMPIRT']   = '0.1';
        $body['nobitex']['amountPrecisions']['PUMPIRT']  = 'junk';
        $this->fakeOptions($body);

        $this->assertSame(10, MarketPrecision::priceTick('BTCIRT')); // live map still parsed and used
        $this->assertFalse(MarketPrecision::isRelevantSymbol('PUMPIRT'));

        $this->log->shouldNotHaveReceived('warning', fn ($m) => $m === 'PRECISION_INVALID');
        $this->log->shouldHaveReceived('debug')
            ->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_INVALID_SKIPPED' && ($ctx['count'] ?? null) === 3)
            ->once();
    }

    public function test_invalid_tick_is_still_warned_for_a_symbol_a_bot_uses(): void
    {
        $this->buildGridSchema();
        BotConfig::create(['name' => 'meme', 'symbol' => 'PUMPIRT', 'grid_spacing' => 1.00]);

        $body = self::OPTIONS;
        $body['nobitex']['pricePrecisions']['PUMPIRT']   = '0.1';
        $body['nobitex']['pricePrecisions']['1KBONKIRT'] = '0.001';
        $this->fakeOptions($body);

        MarketPrecision::priceTick('BTCIRT');

        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_INVALID' && ($ctx['symbol'] ?? null) === 'PUMPIRT')
            ->once();
        $this->log->shouldNotHaveReceived('warning', fn ($m, $ctx = []) => $m === 'PRECISION_INVALID' && ($ctx['symbol'] ?? null) === '1KBONKIRT');
        $this->assertTrue(MarketPrecision::isRelevantSymbol('PUMPIRT'));

        $this->dropGridSchema();
    }

    public function test_symbol_missing_fallback_is_only_logged_for_relevant_symbols(): void
    {
        $this->fakeOptions();

        $this->assertIsInt(MarketPrecision::qtyDecimals('LTCIRT'));  // not allowed, no bot → config
        $this->log->shouldNotHaveReceived('warning', fn ($m) => $m === 'PRECISION_FALLBACK');

        config(['trading.exchange.allowed_symbols' => ['BTCIRT', 'LTCIRT']]);
        MarketPrecision::qtyDecimals('LTCIRT');
        $this->log->shouldHaveReceived('warning')
            ->withArgs(fn ($m, $ctx = []) => $m === 'PRECISION_FALLBACK' && ($ctx['symbol'] ?? null) === 'LTCIRT')
            ->once();
    }

    // ── rounding ─────────────────────────────────────────────────────────

    public function test_round_price_is_side_safe(): void
    {
        config(['trading.exchange.precision_live' => false]); // tick 10 from config

        // bot 48: 225328984910 × 0.985 = 221949050136.35
        $this->assertSame(221949050130, MarketPrecision::roundPrice('221949050136.35', 'BTCIRT', 'buy'));
        $this->assertSame(221949050140, MarketPrecision::roundPrice('221949050136.35', 'BTCIRT', 'sell'));
        $this->assertSame(221949050140, MarketPrecision::roundPrice(221949050140, 'BTCIRT', 'buy'));
        $this->assertSame(221949050140, MarketPrecision::roundPrice(221949050140, 'BTCIRT', 'sell'));
        $this->assertSame(221949050140, MarketPrecision::roundPrice('221949050130.0001', 'BTCIRT', 'SELL'));

        $this->assertTrue(MarketPrecision::isOnTick(221949050140, 'BTCIRT'));
        $this->assertFalse(MarketPrecision::isOnTick(221949050136, 'BTCIRT'));
    }
}
