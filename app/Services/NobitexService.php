<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExchangeClient;
use App\DTOs\ApiOkDto;
use App\DTOs\BalanceDto;
use App\DTOs\CreateOrderDto;
use App\DTOs\CreateOrderResponse;
use App\DTOs\OrderBookDto;
use App\DTOs\OrderStatusDto;
use App\DTOs\WalletsDto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * NobitexService (REST Client)
 * ------------------------------------------------------------------
 * • کلاینت مقاوم برای Nobitex v2 با timeout/retry/backoff + soft rate‑limit + logging
 * • پیاده‌سازی قرارداد ExchangeClient با خروجی DTO
 * • اوردر‌بوک عمومی با زنجیرهٔ fallback: v3 → v2 → /market/stats (ساخت L2 حداقلی)
 * • متدهای خصوصی (wallets/orders/positions/withdraw/whitelist/...) هم پوشش داده شده‌اند
 *
 * Notes
 * - برای endpoint های خصوصی ثبت سفارش ریالی، معمولاً dstCurrency باید «rls» باشد (نه «irt»)
 * - در /market/stats کلیدهای جفت ممکن است «btc-irt» یا «btc-rls» باشند؛ هر دو بررسی می‌شود.
 */
class NobitexService implements ExchangeClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int    $timeout;
    protected int    $retryTimes;
    protected int    $retrySleepMs;

    public function __construct()
    {
        $cfg = (array) config('trading.nobitex', []);

        $this->baseUrl = rtrim(
            (string) ($cfg['base_url']
                ?? (env('NOBITEX_USE_TESTNET', false)
                    ? env('NOBITEX_TESTNET_URL', 'https://testnetapiv2.nobitex.ir')
                    : env('NOBITEX_BASE_URL', 'https://apiv2.nobitex.ir'))),
            '/'
        );

        $this->apiKey       = (string) ($cfg['api_key'] ?? env('NOBITEX_API_KEY', ''));
        $this->timeout      = (int) ($cfg['http']['timeout'] ?? env('NOBITEX_HTTP_TIMEOUT', 8));
        $this->retryTimes   = (int) ($cfg['retry']['times'] ?? env('NOBITEX_RETRY_MAX_ATTEMPTS', 3));
        $this->retrySleepMs = (int) ($cfg['retry']['sleep'] ?? 200);
    }

    /* -----------------------------------------------------------------
     | Core HTTP
     |------------------------------------------------------------------*/
    protected function http(array $headers = []): PendingRequest
    {
        $defaultHeaders = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $req = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withHeaders($defaultHeaders + $headers);

        if ($this->apiKey !== '') {
            $req = $req->withHeaders(['Authorization' => 'Token ' . $this->apiKey]);
        }

        // throw() را در request() هندل می‌کنیم
        return $req;
    }

    /**
     * Unified request: retry/backoff + soft rate limit + error mapping.
     */
    protected function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        array $headers = []
    ): array {
        $url = '/' . ltrim($path, '/');

        // Soft rate limit per-route (best‑effort)
        $rpm = (int) (config('trading.nobitex.rate_limit.rpm', 60) ?: 60);
        $limiterKey = sprintf('nobitex:%s:%s', strtoupper($method), $url);
        $allowed = RateLimiter::attempt($limiterKey, $rpm, fn () => true);
        if (!$allowed) {
            usleep(200_000); // 200ms
        }

        $attempts = max(1, $this->retryTimes);
        $sleepMs  = max(0, $this->retrySleepMs);
        $lastEx   = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $res = match (strtoupper($method)) {
                    'GET'    => $this->http($headers)->get($url, $query),
                    'POST'   => $this->http($headers)->post($url, $json ?? []),
                    'DELETE' => $this->http($headers)->delete($url, $json ?? []),
                    default  => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
                };

                if ($res->failed()) {
                    // HTML/404 → Exception
                    $res->throw();
                }

                $data = $res->json();
                if (!is_array($data)) {
                    throw new \RuntimeException('BadResponse: Non-JSON');
                }

                // {status: failed, code, message}
                if (($data['status'] ?? null) === 'failed') {
                    $this->logFail($method, $url, $query, $json, $data);
                    $this->throwDomainError($data);
                }

                return $data;
            } catch (ConnectionException $e) {
                $lastEx = $e; $this->sleepBackoff($i, $sleepMs);
            } catch (Throwable $e) {
                if ($this->isRetryable($e)) { $lastEx = $e; $this->sleepBackoff($i, $sleepMs); continue; }
                throw $e;
            }
        }

        throw $lastEx ?: new \RuntimeException('Nobitex request failed');
    }

    protected function isRetryable(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return Str::contains($msg, ['429', '5', 'timeout', 'cURL error', 'timed out']);
    }

    protected function sleepBackoff(int $attemptIndex, int $baseSleepMs): void
    {
        $expo = max(1, $attemptIndex + 1);
        $jitter = random_int(0, 250);
        usleep(($baseSleepMs * $expo + $jitter) * 1000);
    }

    protected function logFail(string $method, string $path, array $query, ?array $json, array $data): void
    {
        Log::channel('nobitex')->warning('Nobitex API failed', [
            'method'  => $method,
            'path'    => $path,
            'query'   => $query,
            'json'    => $this->redact($json ?? []),
            'code'    => $data['code'] ?? null,
            'message' => $data['message'] ?? null,
        ]);
    }

    protected function throwDomainError(array $data): never
    {
        $code = (string)($data['code'] ?? 'Unknown');
        $msg  = (string)($data['message'] ?? '');

        $ex = match ($code) {
            'ParseError'                  => new \InvalidArgumentException($msg ?: 'Bad request'),
            'TradeLimitation'             => new \RuntimeException('User KYC level insufficient'),
            'InvalidMarketPair'           => new \DomainException('Invalid market symbol pair'),
            'MarketClosed'                => new \RuntimeException('Market closed'),
            'TradingUnavailable'          => new \RuntimeException('Account trading restricted'),
            'UnsupportedMarginSrc'        => new \DomainException('Unsupported margin asset'),
            'MarginClosed'                => new \RuntimeException('Margin market closed'),
            'AmountUnavailable'           => new \RuntimeException('Delegation pool amount unavailable'),
            'ExceedDlegationLimit',
            'ExceedDelegationLimit'       => new \RuntimeException('Delegation limit exceeded'),
            'InsufficientBalance'         => new \RuntimeException('Insufficient balance'),
            'LeverageTooHigh'             => new \InvalidArgumentException('Leverage too high'),
            'LeverageUnavailable'         => new \RuntimeException('Leverage unavailable for user'),
            'BadPrice'                    => new \InvalidArgumentException('Bad price'),
            'SmallOrder'                  => new \InvalidArgumentException('Order below market minimum'),
            'PriceConditionFailed'        => new \InvalidArgumentException('Price condition failed'),
            'DuplicateOrder'              => new \RuntimeException('Duplicate order in last 10s'),
            'NoOpenPosition'              => new \RuntimeException('No active position'),
            'ExceedLiability'             => new \InvalidArgumentException('Amount exceeds liability'),
            'ExceedTotalAsset'            => new \InvalidArgumentException('Total asset exceeded by order'),
            'WithdrawUnavailable'         => new \RuntimeException('Withdraw unavailable for user'),
            'WithdrawCurrencyUnavailable' => new \RuntimeException('Withdraw disabled for this currency/network'),
            'CoinWithdrawDisabled'        => new \RuntimeException('Coin withdraw temporarily disabled'),
            'InvalidAddressTag'           => new \InvalidArgumentException('Invalid address tag'),
            'MissingAddressTag'           => new \InvalidArgumentException('Missing address tag'),
            'ExchangeRequiredTag'         => new \InvalidArgumentException('Tag required for exchange withdrawals'),
            'RedundantTag'                => new \InvalidArgumentException('Redundant tag'),
            'Invalid2FA', 'InvalidOTP'    => new \RuntimeException('Invalid 2FA/OTP code'),
            'WithdrawAmountLimitation'    => new \RuntimeException('Withdraw amount exceeds limits'),
            'WithdrawLimitReached'        => new \RuntimeException('Too many withdraws with same status'),
            'AmountTooLow'                => new \InvalidArgumentException('Amount below network minimum'),
            'AmountTooHigh'               => new \InvalidArgumentException('Amount above network maximum'),
            'NotWhitelistedTargetAddress' => new \RuntimeException('Address not whitelisted (secure mode)'),
            'DuplicatedAddress'           => new \RuntimeException('Address already exists'),
            'InvalidAddress'              => new \InvalidArgumentException('Invalid address for network'),
            'InvalidTag'                  => new \InvalidArgumentException('Invalid tag for network'),
            'Inactive2FA'                 => new \RuntimeException('2FA inactive for user'),
            'InvalidOTPCode'              => new \RuntimeException('Invalid OTP code'),
            'InvalidCodeLength'           => new \InvalidArgumentException('Anti-phishing code length invalid'),
            'NotFound'                    => new \RuntimeException('Requested resource not found'),
            default                       => new \RuntimeException($msg ?: ('Nobitex failed: ' . $code)),
        };

        throw $ex;
    }

    protected function redact(array $payload): array
    {
        $keys = ['Authorization','token','otp','otpCode','tfaCode','X-TOTP','invoice'];
        $clone = $payload;
        array_walk_recursive($clone, function (&$v, $k) use ($keys) {
            if (in_array($k, $keys, true)) { $v = '***'; }
        });
        return $clone;
    }

    /* -----------------------------------------------------------------
     | ExchangeClient (DTO‑based)
     |------------------------------------------------------------------*/

    /**
     * اوردر‌بوک عمومی – با fallback: v3 → v2 → /market/stats
     */
    public function getOrderBook(string $symbol): OrderBookDto
    {
        $symbol = strtoupper(trim($symbol));
        [$src, $dstForPublic] = $this->splitSymbolPublic($symbol); // e.g. ['btc','irt']
        $marketDashedLower = $src . '-' . $dstForPublic;           // btc-irt

        // 1) v3
        try {
            $data = $this->request('GET', '/market/orderbook-v3', ['symbol' => $symbol]);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook-v3 failed; falling back', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        // 2) v2 با SYMBOL (بدون dash)
        try {
            $data = $this->request('GET', '/market/orderbook', ['symbol' => $symbol]);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook v2 (upper) failed; falling back', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        // 3) v2 با dashed lower (btc-irt)
        try {
            $data = $this->request('GET', '/market/orderbook', ['symbol' => $marketDashedLower]);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook v2 (dashed) failed; falling back to stats', ['symbol' => $symbol, 'market' => $marketDashedLower, 'error' => $e->getMessage()]);
        }

        // 4) /market/stats – چند کلید محتمل را چک می‌کنیم (irt/rls)
        $stats = $this->request('GET', '/market/stats', [
            'srcCurrency' => $src,
            'dstCurrency' => $dstForPublic,
        ]);

        $entry = $this->pickStatsEntry($stats, $src, $dstForPublic);
        if (empty($entry)) {
            throw new \RuntimeException('Stats not found for market ' . $marketDashedLower);
        }

        $raw = $this->buildOrderbookFromStats($entry);
        Log::channel('nobitex')->info('orderbook synthesized from stats', [
            'symbol' => $symbol,
            'market' => $marketDashedLower,
            'latest' => $raw['lastTradePrice'] ?? null,
        ]);

        return OrderBookDto::fromApi($raw, $symbol);
    }

    /** ثبت سفارش جدید (با DTO) */
    public function createOrder(CreateOrderDto $dto): CreateOrderResponse
    {
        $payload = method_exists($dto, 'toApiPayload') ? $dto->toApiPayload() : (array) $dto;
        $data = $this->request('POST', '/market/orders/add', [], $payload);

        if (method_exists(CreateOrderResponse::class, 'fromApi')) {
            /** @var CreateOrderResponse $resp */
            $resp = CreateOrderResponse::fromApi($data);
            return $resp;
        }

        $ok  = ($data['status'] ?? 'ok') === 'ok';
        $id  = $data['order']['id'] ?? $data['id'] ?? null;
        $msg = $data['message'] ?? null;
        return new CreateOrderResponse(ok: $ok, orderId: $id, message: $msg);
    }

    /** لغو سفارش */
    public function cancelOrder(string $orderId): ApiOkDto
    {
        $data = $this->request('POST', '/market/orders/update-status', [], [
            'order'  => $orderId,
            'status' => 'canceled',
        ]);

        if (method_exists(ApiOkDto::class, 'fromApi')) {
            /** @var ApiOkDto $dto */
            $dto = ApiOkDto::fromApi($data);
            return $dto;
        }

        $ok  = ($data['status'] ?? 'ok') === 'ok';
        $msg = $data['message'] ?? null;
        return new ApiOkDto(ok: $ok, message: $msg);
    }

    /**
     * وضعیت چند سفارش (batch)
     * @param array<int,string> $orderIds
     * @return array<int,OrderStatusDto>
     */
    public function getOrdersStatus(array $orderIds): array
    {
        $data = $this->request('POST', '/market/orders/status', [], ['ids' => array_values($orderIds)]);
        $rows = (array) ($data['orders'] ?? []);

        $out = [];
        foreach ($rows as $row) {
            if (method_exists(OrderStatusDto::class, 'fromApi')) {
                $out[] = OrderStatusDto::fromApi((array) $row);
            } else {
                $out[] = new OrderStatusDto(
                    orderId: (string) ($row['id'] ?? ''),
                    status: (string) ($row['status'] ?? 'UNKNOWN'),
                    side:   (string) ($row['type'] ?? ''),
                    execution: (string) ($row['execution'] ?? ''),
                    amount: (string) ($row['amount'] ?? '0'),
                    filled: (string) ($row['matchedAmount'] ?? '0'),
                    priceIRT: isset($row['price']) ? (int) $row['price'] : null,
                    createdAt: isset($row['createdAt']) ? (int) $row['createdAt'] : null,
                    updatedAt: isset($row['updatedAt']) ? (int) $row['updatedAt'] : null,
                );
            }
        }

        return $out;
    }

    /** موجودی یک ارز */
    public function getBalance(string $currency): BalanceDto
    {
        $data = $this->request('POST', '/users/wallets/balance', [], ['currency' => $currency]);

        if (method_exists(BalanceDto::class, 'fromApi')) {
            /** @var BalanceDto $dto */
            $dto = BalanceDto::fromApi($data + ['currency' => $currency]);
            return $dto;
        }

        return new BalanceDto(
            currency: $currency,
            balance:  (string) Arr::get($data, 'balance', '0'),
            locked:   (string) Arr::get($data, 'locked', '0'),
            available:(string) Arr::get($data, 'available', Arr::get($data, 'balance', '0')),
        );
    }

    /** فهرست کیف‌پول‌ها */
    public function getWallets(): WalletsDto
    {
        $data = $this->request('POST', '/users/wallets/list');

        if (method_exists(WalletsDto::class, 'fromApi')) {
            /** @var WalletsDto $dto */
            $dto = WalletsDto::fromApi($data);
            return $dto;
        }

        $wallets = (array) ($data['wallets'] ?? []);
        return new WalletsDto($wallets);
    }

    /* -----------------------------------------------------------------
     | Extra endpoints (array‑based; خارج از قرارداد)
     |------------------------------------------------------------------*/

    /** سفارش مارجین OCO (array‑based) */
    public function createMarginOcoOrder(array $dto): array
    {
        $payload = $dto + ['mode' => 'oco'];
        $data = $this->request('POST', '/market/orders/add', [], $payload);
        return ['orders' => $data['orders'] ?? []];
    }

    /** لیست پوزیشن‌ها */
    public function listPositions(
        ?string $src = null,
        ?string $dst = null,
        string $status = 'active',
        ?int $page = null,
        ?int $pageSize = null
    ): array {
        $query = array_filter([
            'srcCurrency' => $src,
            'dstCurrency' => $dst,
            'status'      => $status,
            'page'        => $page,
            'pageSize'    => $pageSize,
        ], fn($v) => !is_null($v));

        $data = $this->request('GET', '/positions/list', $query);
        return [
            'positions' => $data['positions'] ?? [],
            'hasNext'   => (bool) ($data['hasNext'] ?? false),
        ];
    }

    public function getPositionStatus(int $positionId): array
    {
        $data = $this->request('GET', "/positions/{$positionId}/status");
        return $data['position'] ?? [];
    }

    public function closePosition(int $positionId, array $dto): array
    {
        $data = $this->request('POST', "/positions/{$positionId}/close", [], $dto);
        return $data['order'] ?? [];
    }

    public function editCollateral(int $positionId, string $collateral): array
    {
        $data = $this->request('POST', "/positions/{$positionId}/edit-collateral", [], ['collateral' => $collateral]);
        return $data['position'] ?? [];
    }

    /* -----------------------------------------------------------------
     | Withdraws / Address Book / Whitelist
     |------------------------------------------------------------------*/
    public function createWithdraw(array $dto, ?string $totp = null): array
    {
        $headers = $totp ? ['X-TOTP' => $totp] : [];
        $data = $this->request('POST', '/users/wallets/withdraw', [], $dto, $headers);
        return $data['withdraw'] ?? [];
    }

    public function confirmWithdraw(int $withdrawId, ?int $otp = null): array
    {
        $payload = ['withdraw' => $withdrawId] + ($otp !== null ? ['otp' => $otp] : []);
        $data = $this->request('POST', '/users/wallets/withdraw-confirm', [], $payload);
        return $data['withdraw'] ?? [];
    }

    public function listWithdraws(
        ?int $walletId = null, ?int $page = null, ?int $pageSize = null,
        ?string $from = null, ?string $to = null
    ): array {
        $query = array_filter([
            'wallet'   => $walletId,
            'page'     => $page,
            'pageSize' => $pageSize,
            'from'     => $from,
            'to'       => $to,
        ], fn($v) => !is_null($v));

        $data = $this->request('GET', '/users/wallets/withdraws/list', $query);
        return [
            'withdraws' => $data['withdraws'] ?? [],
            'hasNext'   => (bool) ($data['hasNext'] ?? false),
        ];
    }

    public function getWithdraw(int $withdrawId): array
    {
        $data = $this->request('GET', "/withdraws/{$withdrawId}");
        return $data['withdraw'] ?? [];
    }

    public function addressBookList(?string $network = null): array
    {
        $query = array_filter(['network' => $network]);
        $data = $this->request('GET', '/address_book', $query);
        return $data['data'] ?? [];
    }

    public function addressBookAdd(array $dto): array
    {
        $data = $this->request('POST', '/address_book', [], $dto);
        return $data['data'] ?? [];
    }

    public function addressBookDelete(int $addressId): array
    {
        $this->request('DELETE', "/address_book/{$addressId}/delete");
        return ['status' => 'ok'];
    }

    public function activateWhitelist(): array
    {
        $this->request('POST', '/address_book/whitelist/activate');
        return ['status' => 'ok'];
    }

    public function deactivateWhitelist(string $otpCode, string $tfaCode): array
    {
        $this->request('POST', '/address_book/whitelist/deactivate', [], [
            'otpCode' => $otpCode,
            'tfaCode' => $tfaCode,
        ]);
        return ['status' => 'ok'];
    }

    /* -----------------------------------------------------------------
     | Options v2 + WebSocket
     |------------------------------------------------------------------*/
    public function getOptionsV2(): array
    {
        return Cache::remember('nobitex:options:v2', 300, function () {
            $data = $this->request('GET', '/v2/options');
            return [
                'features' => $data['features'] ?? [],
                'coins'    => $data['coins'] ?? [],
                'nobitex'  => $data['nobitex'] ?? [],
            ];
        });
    }

    /** WebSocket token (for private channels) */
    public function getWebsocketToken(): array
    {
        $data = $this->request('GET', '/auth/ws/token/');
        return ['token' => $data['token'] ?? null];
    }

    /* -----------------------------------------------------------------
     | Convenience helpers (sync با MarketDataLayer/Executor)
     |------------------------------------------------------------------*/

    /**
     * قیمت لحظه‌ای (WS snapshot اولویت دارد؛ در غیر اینصورت از /market/stats)
     */
    public function getCurrentPrice(string $symbol): float
    {
        $symbol = strtoupper(trim($symbol));

        // 1) تلاش از WebSocket snapshot اگر سرویس موجود باشد
        try {
            if (class_exists(\App\Services\NobitexWebSocketService::class)) {
                $ws = app(\App\Services\NobitexWebSocketService::class);
                if (method_exists($ws, 'getLastPriceSnapshot')) {
                    $snap = $ws->getLastPriceSnapshot($symbol);
                    $p = (float)($snap['price'] ?? 0);
                    if ($p > 0) return $p;
                }
            }
        } catch (\Throwable $e) {
            // ignore و برگرد به REST
        }

        // 2) REST /market/stats
        [$src, $dstPublic] = $this->splitSymbolPublic($symbol);
        $data = $this->request('GET', '/market/stats', [
            'srcCurrency' => $src,
            'dstCurrency' => $dstPublic,
        ]);
        $entry = $this->pickStatsEntry($data, $src, $dstPublic);
        if (!$entry) { throw new \RuntimeException("Stats not found for {$src}-{$dstPublic}"); }

        $latest   = (int)($entry['latest']   ?? 0);
        $bestSell = (int)($entry['bestSell'] ?? 0);
        $bestBuy  = (int)($entry['bestBuy']  ?? 0);
        if ($latest > 0) return (float)$latest;
        $asks = $bestSell > 0 ? [[(string)$bestSell, '0']] : [];
        $bids = $bestBuy  > 0 ? [[(string)$bestBuy,  '0']] : [];
        return (float) $this->inferMidPrice($asks, $bids);
    }

    /** آمار بازار + اسپرد/درصد اسپرد/تغییر روزانه (برای مانیتورینگ) */
    public function getMarketStats(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        [$src, $dstPublic] = $this->splitSymbolPublic($symbol);

        $data = $this->request('GET', '/market/stats', [
            'srcCurrency' => $src,
            'dstCurrency' => $dstPublic,
        ]);

        $entry = $this->pickStatsEntry($data, $src, $dstPublic);
        if (!$entry) { throw new \RuntimeException("Stats not found for {$src}-{$dstPublic}"); }

        $bestSell = (int)($entry['bestSell'] ?? 0);
        $bestBuy  = (int)($entry['bestBuy']  ?? 0);
        $latest   = (int)($entry['latest']   ?? 0);

        $spread = ($bestSell > 0 && $bestBuy > 0) ? max(0, $bestSell - $bestBuy) : 0.0;
        $mid    = ($bestSell > 0 && $bestBuy > 0) ? ($bestSell + $bestBuy) / 2 : ($latest > 0 ? $latest : 0.0);
        $spreadPercent = $mid > 0 ? ($spread / $mid) * 100 : 0.0;

        $dayChange = (float)($entry['dayChange'] ?? $entry['24h_change'] ?? 0.0);

        return [
            'symbol'        => $symbol,
            'spread'        => $spread,
            'spreadPercent' => $spreadPercent,
            'dayChange'     => $dayChange,
        ];
    }

    /**
     * خلاصهٔ تمام موجودی‌ها (آرایه ساده) — برای نمایش سریع داشبورد/پنل
     */
    public function getBalances(): array
    {
        $data = $this->request('POST', '/users/wallets/list');
        if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('Nobitex getBalances bad payload');
        }
        $out = [];
        foreach (($data['wallets'] ?? []) as $w) {
            $cur = $w['currency'] ?? null;
            if (!$cur) continue;
            $out[$cur] = [
                'available' => (string) ($w['balance'] ?? '0'),
                'locked'    => (string) ($w['blocked'] ?? '0'),
            ];
        }
        return $out;
    }

    /**
     * متد کمکی «سریع» برای ثبت سفارش با ورودی نماد/جهت/قیمت/حجم (array‑based)
     * side: 'buy'|'sell' — execution: 'limit' فقط
     */
    public function placeOrder(string $symbol, string $side, int $price, string $quantity): array
    {
        $s = strtolower(str_replace('-', '', trim($symbol)));
        if (str_ends_with($s, 'irt')) {
            $src = substr($s, 0, -3); $dst = 'rls'; // برای endpoint خصوصی → rls
        } elseif (str_ends_with($s, 'usdt')) {
            $src = substr($s, 0, -4); $dst = 'usdt';
        } elseif (strlen($s) === 6) {
            $src = substr($s, 0, 3); $dst = substr($s, 3);
        } else {
            throw new \InvalidArgumentException("Unsupported symbol for order: {$symbol}");
        }

        $payload = [
            'type'        => strtolower($side) === 'buy' ? 'buy' : 'sell',
            'execution'   => 'limit',
            'srcCurrency' => $src,
            'dstCurrency' => $dst,
            'amount'      => (string)$quantity,
            'price'       => (string)$price,
        ];

        return $this->request('POST', '/market/orders/add', [], $payload);
    }

    /* -----------------------------------------------------------------
     | Helpers
     |------------------------------------------------------------------*/

    /**
     * BTCIRT, BTC-IRT → ['btc','irt'] (برای مسیرهای عمومی/نمایشی)
     */
    protected function splitSymbolPublic(string $symbol): array
    {
        $s = strtoupper(trim($symbol));
        if (str_contains($s, '-')) { [$a, $b] = explode('-', $s, 2); }
        else { $a = substr($s, 0, 3); $b = substr($s, 3); }
        return [strtolower($a ?? ''), strtolower($b ?? '')];
    }

    /**
     * انتخاب رکورد صحیح از /market/stats (btc-irt یا btc-rls)
     * @return array<string,mixed>
     */
    protected function pickStatsEntry(array $statsResp, string $src, string $dstPublic): array
    {
        $candidates = [
            $src . '-' . $dstPublic, // e.g. btc-irt
            $src . '-rls',
            $src . '-irt',
        ];
        foreach ($candidates as $key) {
            $entry = (array) Arr::get($statsResp, 'stats.' . $key, []);
            if (!empty($entry)) return $entry;
        }
        return [];
    }

    /** نگاشت payload اوردر‌بوک به DTO خودمان */
    protected function mapOrderbookPayloadToDto(array $data, string $symbol): OrderBookDto
    {
        $asks = $this->normalizeL2((array) Arr::get($data, 'asks', []));
        $bids = $this->normalizeL2((array) Arr::get($data, 'bids', []));

        $lastTradePrice = (int) ($data['lastTradePrice'] ?? $data['lastPrice'] ?? 0);
        if ($lastTradePrice <= 0) { $lastTradePrice = $this->inferMidPrice($asks, $bids); }

        $lastUpdate = (int) ($data['lastUpdate'] ?? 0);
        if ($lastUpdate <= 0) {
            $sec = (int) ($data['time'] ?? 0);
            $lastUpdate = $sec > 0 ? ($sec * 1000) : (int) round(microtime(true) * 1000);
        }

        $raw = [
            'asks'           => $asks,
            'bids'           => $bids,
            'lastTradePrice' => $lastTradePrice,
            'lastUpdate'     => $lastUpdate,
            'time'           => (int) floor($lastUpdate / 1000),
        ];

        return OrderBookDto::fromApi($raw, $symbol);
    }

    /** ساخت اوردر‌بوک حداقلی از stats */
    protected function buildOrderbookFromStats(array $entry): array
    {
        $bestSell = (int) ($entry['bestSell'] ?? 0); // lowest ask
        $bestBuy  = (int) ($entry['bestBuy']  ?? 0); // highest bid
        $latest   = (int) ($entry['latest']   ?? 0);

        $asks = $bestSell > 0 ? [[(string) $bestSell, '0']] : [];
        $bids = $bestBuy  > 0 ? [[(string) $bestBuy,  '0']] : [];

        if ($latest <= 0) { $latest = $this->inferMidPrice($asks, $bids); }

        $nowMs = (int) round(microtime(true) * 1000);

        return [
            'asks'           => $asks,
            'bids'           => $bids,
            'lastTradePrice' => $latest,
            'lastUpdate'     => $nowMs,
            'time'           => (int) floor($nowMs / 1000),
        ];
    }

    /** @param array<int,mixed> $rows */
    protected function normalizeL2(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && count($row) >= 2) {
                $out[] = [(string) $row[0], (string) $row[1]];
            } elseif (is_array($row) && isset($row['price'], $row['amount'])) {
                $out[] = [(string) $row['price'], (string) $row['amount']];
            }
        }
        return $out;
    }

    protected function inferMidPrice(array $asks, array $bids): int
    {
        $ask = $this->toIntPrice($asks[0] ?? null);
        $bid = $this->toIntPrice($bids[0] ?? null);
        if ($ask === null && $bid === null) return 0;
        if ($ask === null) return $bid;
        if ($bid === null) return $ask;
        return (int) floor(($ask + $bid) / 2);
    }

    protected function toIntPrice(?array $row): ?int
    {
        if (!$row) return null;
        $p = (int) ($row[0] ?? 0);
        return $p > 0 ? $p : null;
    }
    
    /**
     * Health check endpoint - tests API connection and authentication
     * Returns status compatible with both admin panel displays
     */
    public function healthCheck(): array
    {
        $startTime = microtime(true);

        try {
            // Use GET /users/profile as it's simpler and requires auth (perfect for connection test)
            $data = $this->request('GET', '/users/profile');

            $responseTime = microtime(true) - $startTime;
            $isOk = ($data['status'] ?? null) === 'ok';

            return [
                'ok'               => $isOk,
                'status'           => $isOk ? 'ok' : 'failed',
                'overall_status'   => $isOk ? 'healthy' : 'unhealthy',
                'response_time'    => $responseTime,
                'response_time_ms' => round($responseTime * 1000, 2),
                'mode'             => config('trading.grid.simulation', false) ? 'simulation' : 'live',
                'endpoint'         => $this->baseUrl . '/users/profile',
            ];
        } catch (\Throwable $e) {
            $responseTime = microtime(true) - $startTime;

            return [
                'ok'               => false,
                'status'           => 'failed',
                'overall_status'   => 'unhealthy',
                'error'            => $e->getMessage(),
                'response_time'    => $responseTime,
                'response_time_ms' => round($responseTime * 1000, 2),
                'mode'             => config('trading.grid.simulation', false) ? 'simulation' : 'live',
                'endpoint'         => $this->baseUrl . '/users/profile',
            ];
        }
    }
}
