<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExchangeClient;
use App\Contracts\RateLimiter as RateLimiterContract;
use App\Exceptions\AmbiguousOrderSubmissionException;
use App\Exceptions\OrderNotFoundException;
use App\DTOs\ApiOkDto;
use App\DTOs\BalanceDto;
use App\DTOs\CreateOrderDto;
use App\DTOs\CreateOrderResponse;
use App\DTOs\OrderBookDto;
use App\DTOs\OrderStatusDto;
use App\DTOs\WalletsDto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
    protected float  $connectTimeout;
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

        $this->apiKey         = (string) ($cfg['api_key'] ?? env('NOBITEX_API_KEY', ''));
        $this->timeout        = (int) ($cfg['http']['timeout'] ?? env('NOBITEX_HTTP_TIMEOUT', 8));
        // Separate connect budget: distinguishes "couldn't reach the server"
        // (connect timeout) from "server is slow to answer" (read timeout).
        $this->connectTimeout = (float) ($cfg['http']['connect_timeout'] ?? env('NOBITEX_HTTP_CONNECT_TIMEOUT', 5.0));
        $this->retryTimes     = (int) ($cfg['retry']['times'] ?? env('NOBITEX_RETRY_MAX_ATTEMPTS', 3));
        $this->retrySleepMs = (int) ($cfg['retry']['sleep'] ?? 200);
    }

    /* -----------------------------------------------------------------
     | Core HTTP
     |------------------------------------------------------------------*/
    protected function http(array $headers = [], bool $signed = false): PendingRequest
    {
        $defaultHeaders = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            // The config key is named `signed_user_agent` for historical reasons
            // (it once applied only to the signed path), but the TraderBot UA now
            // rides on EVERY request — signed and public (orderbook/stats/OHLC)
            // alike — per Nobitex's recommendation to identify bot traffic.
            'User-Agent'   => (string) config('trading.nobitex.signed_user_agent', 'TraderBot/Griding-1.0'),
        ];

        $req = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->acceptJson()
            ->withHeaders($defaultHeaders + $headers);

        if (!$signed && $this->apiKey !== '') {
            // Legacy `Authorization: Token` header for unsigned authenticated
            // calls. On the Ed25519 signing path (signed: true) this is
            // deliberately skipped: the Nobitex-* signature headers — supplied by
            // the caller, computed from method/full_path/raw_body — replace it.
            $req = $req->withHeaders(['Authorization' => 'Token ' . $this->apiKey]);
        }

        // throw() را در request() هندل می‌کنیم
        return $req;
    }

    /** Lazily-built Ed25519 signer (keys read from config → env). */
    protected ?NobitexRequestSigner $signer = null;

    protected function signer(): NobitexRequestSigner
    {
        return $this->signer ??= NobitexRequestSigner::fromConfig();
    }

    /**
     * Send one authenticated request via the Ed25519 signing scheme.
     *
     * The signature is computed HERE — at the point where the final method,
     * full path (incl. query string), and raw body bytes are all known — so what
     * we sign is byte-for-byte what we send. json_encode is the single source of
     * truth for both the wire body and the signed body, so they can never
     * diverge. An empty payload signs and sends an empty body ("") — the shape
     * proven to return HTTP 200 for POST /users/wallets/list.
     *
     * @param array<string,mixed>       $query
     * @param array<string,mixed>|null  $json
     * @param array<string,mixed>       $headers
     */
    protected function sendSigned(string $method, string $url, array $query, ?array $json, array $headers): \Illuminate\Http\Client\Response
    {
        $rawBody = ($json === null || $json === [])
            ? ''
            : (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // full_path MUST include the query string when present — the signature has
        // to cover exactly what is requested.
        $fullPath = $url;
        if ($method === 'GET' && $query !== []) {
            $qs = http_build_query($query);
            if ($qs !== '') {
                $fullPath .= '?' . $qs;
            }
        }

        $signHeaders = $this->signer()->signRequest($method, $fullPath, $rawBody);

        $req = $this->http($headers + $signHeaders, signed: true);

        // Send the exact signed bytes. withBody + send transmits the raw body
        // byte-for-byte — byte-identical to what was signed — rather than letting
        // Laravel re-encode via ->post([]) (which would emit "{}"/form data).
        // An empty payload signs and sends an empty body; Content-Type is
        // harmless (proven on the host: a bodyless POST /users/wallets/list with
        // the PADDED signature returns HTTP 200 with or without a Content-Type),
        // so there is no special case here — the padded signature is what the
        // API requires, and matching that is the whole fix.
        return $req
            ->withBody($rawBody, 'application/json')
            ->send($method, $fullPath);
    }

    /**
     * Unified request: retry/backoff + soft rate limit + error mapping.
     *
     * $idempotentRetry expresses, AT THE CALL SITE, whether replaying this exact
     * request after an ambiguous failure is safe. It defaults to true so every
     * existing caller — all GETs, cancelOrder, the wallet/status reads — keeps
     * today's resilient behaviour untouched. Order-creating and value-moving
     * POSTs (see the private methods below) pass false: for those, a
     * ConnectionException or a server 408/5xx is treated as "the exchange may
     * already have done this", so request() surfaces immediately instead of
     * blindly resending and risking a duplicate order/withdraw. A 429 stays
     * retryable even for those, because a rate-limit rejection is definitive —
     * the request was refused before it could act. A bool (rather than an enum
     * or options array) is the minimal-diff shape that reads clearly here: there
     * are exactly two policies, and the flag names the axis directly.
     */
    protected function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        array $headers = [],
        bool $idempotentRetry = true,
        bool $signed = false
    ): array {
        $url = '/' . ltrim($path, '/');

        // Rate limiting. Two mutually-exclusive paths, selected by a
        // default-OFF flag so this ships dark:
        //
        //  • enforce=false (default) → the LEGACY soft path below, byte-for-byte
        //    today's behaviour: a best-effort per-route attempt that, when the
        //    per-route budget is hit, naps 200ms ONCE and then sends anyway. It
        //    never actually prevents an over-limit request.
        //
        //  • enforce=true → the soft path is skipped entirely and the new global
        //    blocking gate runs INSIDE the retry loop instead (see below), so it
        //    can genuinely block or throw before each send.
        $enforce = (bool) (config('trading.nobitex.rate_limit.enforce', false));

        if (!$enforce) {
            // Soft rate limit per-route (best‑effort)
            $rpm = (int) (config('trading.nobitex.rate_limit.rpm', 60) ?: 60);
            $limiterKey = sprintf('nobitex:%s:%s', strtoupper($method), $url);
            $allowed = RateLimiter::attempt($limiterKey, $rpm, fn () => true);
            if (!$allowed) {
                usleep(200_000); // 200ms
            }
        }

        $attempts = max(1, $this->retryTimes);
        $sleepMs  = max(0, $this->retrySleepMs);
        $lastEx   = null;

        for ($i = 0; $i < $attempts; $i++) {
            // The gate sits at the TOP of every loop iteration, not once before
            // the loop: a retried request is still a request against the
            // account budget, so each attempt must pass the gate. It blocks up
            // to max_wait_ms for a permit and throws RateLimitExceededException
            // (non-retryable) rather than send over-limit. Off unless enforced.
            if ($enforce) {
                $this->rateGate();
            }

            try {
                $res = $signed
                    ? $this->sendSigned(strtoupper($method), $url, $query, $json, $headers)
                    : match (strtoupper($method)) {
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
                // Transport-level failure. For an idempotent call it is always
                // retried (formula backoff — no server signal to honour). For a
                // non-idempotent call it is AMBIGUOUS: connect- and read-timeouts
                // both surface as ConnectionException (cURL 28) and are
                // indistinguishable, so the order may already be on the book.
                // Refuse to retry and surface it wrapped, so the caller's
                // submission_unknown path triggers instead of a blind resend.
                if (!$idempotentRetry) {
                    $this->logSuppressedOrderRetry($url, $json, 'ConnectionException (connect/read timeout indistinguishable — order may already be placed)');
                    throw new AmbiguousOrderSubmissionException($url, $this->clientRefOf($json), $e);
                }
                $lastEx = $e; $this->sleepBackoff($i, $sleepMs);
            } catch (Throwable $e) {
                if ($this->isRetryable($e, $idempotentRetry)) { $lastEx = $e; $this->sleepBackoff($i, $sleepMs, $e); continue; }

                // Non-retryable. On a non-idempotent call, a server 408/5xx is
                // ambiguous (the exchange may have created the order before the
                // error), so wrap it the same way; domain errors / BadResponse
                // stay exactly as before — they are not RequestExceptions and
                // mean the order was definitively NOT created.
                if (!$idempotentRetry && $this->isAmbiguousServerFailure($e)) {
                    $this->logSuppressedOrderRetry($url, $json, sprintf('HTTP %s (server may have created the order before failing)', $e instanceof RequestException ? (string) $e->response?->status() : '?'));
                    throw new AmbiguousOrderSubmissionException($url, $this->clientRefOf($json), $e);
                }

                throw $e;
            }
        }

        throw $lastEx ?: new \RuntimeException('Nobitex request failed');
    }

    /**
     * Does this failure, on a non-idempotent order POST, leave the outcome
     * unknown? True only for a server-side 408 or 5xx RequestException — the
     * exchange answered late/erroring and may already have created the order.
     * Domain errors and BadResponse are plain RuntimeExceptions (not
     * RequestExceptions) and mean the order was NOT created, so they return
     * false and surface unchanged.
     */
    protected function isAmbiguousServerFailure(Throwable $e): bool
    {
        if (!$e instanceof RequestException) {
            return false;
        }
        $status = $e->response?->status();
        return $status !== null && ($status === 408 || $status >= 500);
    }

    /** Pull the clientOrderId tag out of the JSON payload for logging/exception context. */
    protected function clientRefOf(?array $json): ?string
    {
        $ref = $json['clientOrderId'] ?? null;
        return $ref !== null ? (string) $ref : null;
    }

    /**
     * Loud breadcrumb at the point a retry is suppressed on an order-creating
     * POST — the line an operator follows when reconciling a possibly-placed
     * order. Names the endpoint, the clientOrderId (if any), and why we did
     * not resend.
     */
    protected function logSuppressedOrderRetry(string $url, ?array $json, string $reason): void
    {
        Log::channel('nobitex')->error('Nobitex order retry SUPPRESSED (ambiguous outcome)', [
            'endpoint'        => $url,
            'client_order_id' => $this->clientRefOf($json),
            'reason'          => $reason,
        ]);
    }

    /**
     * Global enforcing rate gate (only reached when
     * config('trading.nobitex.rate_limit.enforce') is true).
     *
     * Uses a single account-wide key ('global') — NOT a per-method/per-path key
     * — so the whole Nobitex account shares one budget, matching how Nobitex
     * actually meters. Blocks up to max_wait_ms for a permit; on timeout the
     * limiter throws App\Exceptions\RateLimitExceededException, which is not a
     * RequestException and is therefore never retried by isRetryable().
     */
    protected function rateGate(): void
    {
        $maxWaitMs = (int) config('trading.nobitex.rate_limit.max_wait_ms', 10_000);

        app(RateLimiterContract::class)->block('global', $maxWaitMs, 1);
    }

    /**
     * Status/type-based retry classification (replaces the old substring sniff).
     *
     * Only a genuine HTTP-layer RequestException with a transient status is
     * retryable. Retry keys on the exception TYPE plus the real HTTP status,
     * never on message text — so a 4xx body echoing an IRT price like "150000"
     * can no longer masquerade as retryable.
     *
     *  • RequestException  → retryable iff response status ∈ retryableStatuses()
     *                        (config http_statuses, which now includes 408).
     *                        400/401/403/404/422/... do NOT retry.
     *  • Domain exceptions from throwDomainError() (RuntimeException /
     *    InvalidArgumentException / DomainException) are NOT RequestExceptions,
     *    so they fall through to false and are NEVER retried. This makes
     *    DuplicateOrder's non-retry explicit by classification rather than by
     *    the accident of its message lacking a '5'.
     *  • BadResponse (Non-JSON RuntimeException, :125) also falls through to
     *    false. By the time it is thrown, $res->failed() has already returned
     *    false — i.e. the server answered with a 2xx status but a malformed
     *    body; retrying an identical request (a POST could double-place an
     *    order) will not help. Transient gateway errors (e.g. a Cloudflare 5xx
     *    HTML page) carry a 5xx status and are thrown as RequestExceptions by
     *    $res->throw() long before this branch, so they stay retryable via the
     *    status path. Hence BadResponse is deliberately NON-retryable.
     *  • ConnectionException never reaches here — it is caught first in the
     *    retry loop (and, for non-idempotent calls, refused there).
     *
     * $idempotentRetry narrows the policy for order-creating POSTs: when false,
     * ONLY a 429 is retryable. A 429 is a definitive rate-limit rejection — the
     * exchange refused the request before it could create anything — so
     * resending is safe. A 408/5xx, by contrast, is ambiguous (the order may
     * have been created before the error) and must NOT be retried, so it is
     * excluded from the set here and handled as an ambiguous failure by request().
     */
    protected function isRetryable(Throwable $e, bool $idempotentRetry = true): bool
    {
        if ($e instanceof RequestException) {
            $status = $e->response?->status();
            if ($status === null) {
                return false;
            }
            if (!$idempotentRetry) {
                return $status === 429;
            }
            return in_array($status, $this->retryableStatuses(), true);
        }

        return false;
    }

    /**
     * The set of HTTP statuses that warrant a retry. Sourced from
     * config('trading.nobitex.retry.http_statuses') — the single source of
     * truth — which now ships 408 alongside {429,500,502,503,504}. The literal
     * fallback mirrors that set so the classifier is safe even if config is
     * unavailable.
     *
     * @return array<int,int>
     */
    protected function retryableStatuses(): array
    {
        $statuses = (array) config('trading.nobitex.retry.http_statuses', [408, 429, 500, 502, 503, 504]);
        return array_values(array_unique(array_map('intval', $statuses)));
    }

    protected function sleepBackoff(int $attemptIndex, int $baseSleepMs, ?Throwable $e = null): void
    {
        usleep($this->computeSleepMs($attemptIndex, $baseSleepMs, $e) * 1000);
    }

    /**
     * Compute the pre-retry delay in milliseconds. Extracted as a seam so tests
     * can assert the chosen delay without actually sleeping.
     *
     * On a 429 carrying a numeric Retry-After header (seconds form), honour the
     * server signal instead of the formula, capped by config retry.max_ms. Any
     * other retryable case (incl. a 429 with no/blank/non-numeric header) uses
     * the existing exponential-backoff-with-jitter formula.
     */
    protected function computeSleepMs(int $attemptIndex, int $baseSleepMs, ?Throwable $e = null): int
    {
        if ($e instanceof RequestException && $e->response?->status() === 429) {
            $retryAfter = $e->response->header('Retry-After');
            if ($retryAfter !== '' && is_numeric($retryAfter)) {
                $ms  = (int) round(((float) $retryAfter) * 1000);
                $cap = (int) config('trading.nobitex.retry.max_ms', 4_000);
                return max(0, min($ms, $cap));
            }
        }

        $expo = max(1, $attemptIndex + 1);
        $jitter = random_int(0, 250);
        return $baseSleepMs * $expo + $jitter;
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
            // Dedicated subtype (still a RuntimeException, same message) so the
            // Step 7 reconciler can recognise a definitive "does not exist"
            // answer by type instead of by message sniffing.
            'NotFound'                    => new OrderNotFoundException('Requested resource not found'),
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

        // 1) v3 PATH form (documented primary): GET /v3/orderbook/{symbol}
        //    — symbol in the PATH (e.g. /v3/orderbook/BTCIRT), on the apiv2 base.
        //    Response family (bids/asks/status/lastUpdate/lastTradePrice) is the
        //    same one mapOrderbookPayloadToDto() already handles.
        try {
            $data = $this->request('GET', '/v3/orderbook/' . $symbol);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook v3 (path form) failed; falling back', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        // 2) v3 legacy alias: GET /market/orderbook-v3?symbol={symbol} (query form)
        try {
            $data = $this->request('GET', '/market/orderbook-v3', ['symbol' => $symbol]);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook-v3 failed; falling back', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        // 3) v2 با SYMBOL (بدون dash)
        try {
            $data = $this->request('GET', '/market/orderbook', ['symbol' => $symbol]);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook v2 (upper) failed; falling back', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        // 4) v2 با dashed lower (btc-irt)
        try {
            $data = $this->request('GET', '/market/orderbook', ['symbol' => $marketDashedLower]);
            return $this->mapOrderbookPayloadToDto($data, $symbol);
        } catch (Throwable $e) {
            Log::channel('nobitex')->notice('orderbook v2 (dashed) failed; falling back to stats', ['symbol' => $symbol, 'market' => $marketDashedLower, 'error' => $e->getMessage()]);
        }

        // 5) /market/stats – چند کلید محتمل را چک می‌کنیم (irt/rls)
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

        // Debug logging to verify payload types and values
        Log::info('Nobitex API payload', [
            'payload' => $payload,
            'amount_type' => gettype($payload['amount'] ?? null),
            'price_type' => gettype($payload['price'] ?? null),
            'amount_value' => $payload['amount'] ?? null,
            'price_value' => $payload['price'] ?? null,
        ]);

        // Order-creating POST → non-idempotent: never blind-retry an ambiguous
        // failure, or we risk placing this order twice with real money.
        // Ed25519-signed: the exact JSON body ($payload → json_encode in
        // sendSigned) is what is signed AND sent, byte-for-byte.
        $data = $this->request('POST', '/market/orders/add', [], $payload, [], idempotentRetry: false, signed: true);

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
        // Cancel is effectively idempotent: re-cancelling an already-cancelled
        // (or already-filled) order is harmless, and losing a cancel to a
        // duplicate leaves a stale order on the book — the risky direction. So
        // it keeps the DEFAULT retry policy (idempotentRetry: true), unlike the
        // order-creating POSTs.
        $data = $this->request('POST', '/market/orders/update-status', [], [
            'order'  => $orderId,
            'status' => 'canceled',
        ], signed: true);

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
     * وضعیت چند سفارش (Nobitex API requires single order ID per request)
     * @param array<int,string> $orderIds
     * @return array<int,OrderStatusDto>
     */
    public function getOrdersStatus(array $orderIds): array
    {
        $out = [];

        foreach ($orderIds as $orderId) {
            try {
                // Call API once per order (Nobitex doesn't support batch)
                $data = $this->request('POST', '/market/orders/status', [], ['id' => $orderId], signed: true);

                // Response has 'order' (singular) not 'orders' (plural)
                $row = (array) ($data['order'] ?? []);

                if (empty($row)) {
                    Log::channel('trading')->warning('Empty order response from Nobitex', [
                        'order_id' => $orderId
                    ]);
                    continue;
                }

                if (method_exists(OrderStatusDto::class, 'fromApi')) {
                    $out[] = OrderStatusDto::fromApi($row);
                } else {
                    $out[] = new OrderStatusDto(
                        orderId: (string) ($row['id'] ?? ''),
                        status: (string) ($row['status'] ?? 'UNKNOWN'),
                        side: (string) ($row['type'] ?? ''),
                        execution: (string) ($row['execution'] ?? ''),
                        amount: (string) ($row['amount'] ?? '0'),
                        filled: (string) ($row['matchedAmount'] ?? '0'),
                        priceIRT: isset($row['price']) ? (int) $row['price'] : null,
                        createdAt: isset($row['created_at']) ? strtotime($row['created_at']) : null,
                        updatedAt: isset($row['updated_at']) ? strtotime($row['updated_at']) : null,
                    );
                }
            } catch (\Exception $e) {
                Log::channel('trading')->error('Failed to get order status', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage()
                ]);
                // Continue with other orders
            }
        }

        return $out;
    }

    /* -----------------------------------------------------------------
     | Read-only reconciliation lookups (Phase 12 Step 7)
     |------------------------------------------------------------------
     | The methods below are STRICTLY read-only against the exchange and
     | exist for the submission_unknown reconciler. None existed before
     | Step 7; the Nobitex API documents all of these capabilities (order
     | status by clientOrderId; the account's order list; the account's
     | trade history). Our order payloads tag orders with the documented
     | 'clientOrderId' field (Step 7b), so the lookup below is expected to
     | resolve orders we actually placed — but the reconciler still never
     | concludes "absent" from this probe alone (see SubmissionReconciler
     | for the corroborating open-orders and trade-history checks).
     |------------------------------------------------------------------*/

    /**
     * Look up a single order by the client-supplied id it was created with.
     *
     * POST /market/orders/status is a pure read despite the POST verb, so it
     * keeps the default idempotent retry policy.
     *
     * ⚠ Nobitex officially marks the clientOrderId parameter as EXPERIMENTAL
     * and may change it — a future API change here is a known risk. Also per
     * the docs, clientOrderId is only searched among open/active/inactive
     * orders: a FILLED order answers NotFound, which is why the reconciler
     * additionally consults listRecentTrades() before concluding absence.
     *
     * @return array<string,mixed>|null The raw order payload, or null ONLY on
     *         an explicit NotFound answer from the exchange. Every other
     *         failure (transport, 5xx, malformed body) throws — callers must
     *         treat those as "could not determine", never as absence.
     */
    public function getOrderByClientOrderId(string $clientOrderId): ?array
    {
        try {
            $data = $this->request('POST', '/market/orders/status', [], [
                'clientOrderId' => $clientOrderId,
            ], signed: true);
        } catch (OrderNotFoundException) {
            return null;
        }

        $row = (array) ($data['order'] ?? []);
        if ($row === []) {
            // status ok but no order payload — an answer we cannot interpret.
            // Throw rather than return null: null means definitive absence.
            throw new \RuntimeException('BadResponse: ok status without order payload');
        }

        return $row;
    }

    /**
     * List the account's OPEN orders for one market.
     *
     * GET /market/orders/list. The IRT→rls mapping matches the private
     * order-placement endpoints (see GridOrderExecutor::splitSymbol).
     * details=2 asks for the fuller order objects (incl. clientOrderId
     * where the exchange has one).
     *
     * Authenticated via the Ed25519 signed path (signed: true) — the query
     * string is part of the signed full_path, so the signature covers exactly
     * what is requested. Same mechanism getBalances/the order methods use.
     *
     * @return array<int,array<string,mixed>> Raw order rows (possibly empty).
     */
    public function listOpenOrders(string $symbol): array
    {
        [$src, $dst] = GridOrderExecutor::splitSymbol($symbol);

        $data = $this->request('GET', '/market/orders/list', [
            'srcCurrency' => $src,
            'dstCurrency' => $dst,
            'status'      => 'open',
            'details'     => 2,
        ], signed: true);

        return array_map(
            static fn ($o) => (array) $o,
            array_values((array) ($data['orders'] ?? []))
        );
    }

    /**
     * List the account's recent trades for one market.
     *
     * GET /market/trades/list — the account's executed trades, newest first.
     * Same IRT→rls mapping as the other private market endpoints. A pure
     * read, so it keeps the default idempotent retry policy.
     *
     * Exists for the reconciler's filled-order gap: clientOrderId lookups
     * only cover open/active/inactive orders (see getOrderByClientOrderId),
     * so an order that filled while its submission outcome was unknown is
     * invisible to both the status probe and the open-orders list. Its
     * trades, however, appear here.
     *
     * Authenticated via the Ed25519 signed path (signed: true) — the query
     * string is part of the signed full_path, so the signature covers exactly
     * what is requested. Same mechanism getBalances/the order methods use.
     *
     * @return array<int,array<string,mixed>> Raw trade rows (possibly empty),
     *         each carrying at least orderId/type/price/amount/timestamp.
     */
    public function listRecentTrades(string $symbol): array
    {
        [$src, $dst] = GridOrderExecutor::splitSymbol($symbol);

        $data = $this->request('GET', '/market/trades/list', [
            'srcCurrency' => $src,
            'dstCurrency' => $dst,
        ], signed: true);

        return array_map(
            static fn ($t) => (array) $t,
            array_values((array) ($data['trades'] ?? []))
        );
    }

    /** موجودی یک ارز */
    public function getBalance(string $currency): BalanceDto
    {
        $data = $this->request('POST', '/users/wallets/balance', [], ['currency' => $currency], signed: true);

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
        // Also posts to /market/orders/add → non-idempotent, same as createOrder.
        // Ed25519-signed, mirroring createOrder: the exact JSON body is signed and sent.
        $data = $this->request('POST', '/market/orders/add', [], $payload, [], idempotentRetry: false, signed: true);
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

        $data = $this->request('GET', '/positions/list', $query, signed: true);
        return [
            'positions' => $data['positions'] ?? [],
            'hasNext'   => (bool) ($data['hasNext'] ?? false),
        ];
    }

    public function getPositionStatus(int $positionId): array
    {
        $data = $this->request('GET', "/positions/{$positionId}/status", signed: true);
        return $data['position'] ?? [];
    }

    public function closePosition(int $positionId, array $dto): array
    {
        // Creates a closing order → non-idempotent; a blind retry could open a
        // second closing order.
        $data = $this->request('POST', "/positions/{$positionId}/close", [], $dto, [], idempotentRetry: false, signed: true);
        return $data['order'] ?? [];
    }

    public function editCollateral(int $positionId, string $collateral): array
    {
        // Moves collateral on the position → non-idempotent (a resend could
        // apply the collateral change twice).
        $data = $this->request('POST', "/positions/{$positionId}/edit-collateral", [], ['collateral' => $collateral], [], idempotentRetry: false, signed: true);
        return $data['position'] ?? [];
    }

    /* -----------------------------------------------------------------
     | Withdraws / Address Book / Whitelist
     |------------------------------------------------------------------*/
    public function createWithdraw(array $dto, ?string $totp = null): array
    {
        $headers = $totp ? ['X-TOTP' => $totp] : [];
        // Creates a withdraw (MOVES money) → non-idempotent: a blind retry after
        // an ambiguous failure could submit the withdrawal twice.
        $data = $this->request('POST', '/users/wallets/withdraw', [], $dto, $headers, idempotentRetry: false);
        return $data['withdraw'] ?? [];
    }

    public function confirmWithdraw(int $withdrawId, ?int $otp = null): array
    {
        $payload = ['withdraw' => $withdrawId] + ($otp !== null ? ['otp' => $otp] : []);
        // Executes/moves the withdraw → non-idempotent, same reasoning as create.
        $data = $this->request('POST', '/users/wallets/withdraw-confirm', [], $payload, [], idempotentRetry: false);
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
        $data = $this->request('GET', '/address_book', $query, signed: true);
        return $data['data'] ?? [];
    }

    public function addressBookAdd(array $dto): array
    {
        // Creates a (withdrawal-target) address book entry → non-idempotent; a
        // duplicate resend would attempt to create the same whitelisted address
        // twice. Losing this to a blind retry is not a money move, but it MODIFIES
        // security-sensitive state, so it gets the conservative policy.
        $data = $this->request('POST', '/address_book', [], $dto, [], idempotentRetry: false, signed: true);
        return $data['data'] ?? [];
    }

    public function addressBookDelete(int $addressId): array
    {
        $this->request('DELETE', "/address_book/{$addressId}/delete", signed: true);
        return ['status' => 'ok'];
    }

    public function activateWhitelist(): array
    {
        $this->request('POST', '/address_book/whitelist/activate', signed: true);
        return ['status' => 'ok'];
    }

    public function deactivateWhitelist(string $otpCode, string $tfaCode): array
    {
        $this->request('POST', '/address_book/whitelist/deactivate', [], [
            'otpCode' => $otpCode,
            'tfaCode' => $tfaCode,
        ], signed: true);
        return ['status' => 'ok'];
    }

    /* -----------------------------------------------------------------
     | Options v2 + WebSocket
     |------------------------------------------------------------------*/
    public function getOptionsV2(): array
    {
        return Cache::remember('nobitex:options:v2', 300, function () {
            $data = $this->request('GET', '/v2/options', signed: true);
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
     * تاریخچهٔ کندل/OHLC (فرمت TradingView UDF) — عمومی و بدون امضا.
     *
     * GET /market/udf/history. برخلاف بقیهٔ مسیرهای عمومی، این endpoint از
     * srcCurrency/dstCurrency استفاده نمی‌کند: نماد عیناً به‌صورت BTCIRT (uppercase)
     * در پارامتر `symbol` ارسال می‌شود و از splitSymbolPublic() عبور نمی‌کند.
     *
     * پاسخ UDF به آرایهٔ نرمال‌شده تبدیل می‌شود. مقادیر o/h/l/c/v به‌صورت STRING
     * نگه داشته می‌شوند (نه float) تا روی مقادیر بزرگ ریالی دقت از دست نرود —
     * هم‌راستا با انضباط Money/BCMath پروژه. `t` یک int (unix seconds) می‌ماند.
     *
     * @param string   $symbol     نماد AS-IS مثل "BTCIRT"
     * @param string   $resolution یکی از: 1,5,15,30,60,180,240,360,720,D,1D,2D,3D
     * @param int      $to         unix seconds (الزامی)
     * @param int|null $from       unix seconds (اختیاری)
     * @param int|null $countback  اختیاری؛ در صورت >۵۰۰ به ۵۰۰ محدود می‌شود (سقف مستندات)
     * @param int      $page       اختیاری، پیش‌فرض ۱
     * @return array{status:string,candles:array<int,array{t:int,o:string,h:string,l:string,c:string,v:string}>,errmsg:?string}
     */
    public function getOhlc(string $symbol, string $resolution, int $to, ?int $from = null, ?int $countback = null, int $page = 1): array
    {
        $allowedResolutions = ['1', '5', '15', '30', '60', '180', '240', '360', '720', 'D', '1D', '2D', '3D'];
        if (!in_array($resolution, $allowedResolutions, true)) {
            throw new \InvalidArgumentException('Unsupported OHLC resolution: ' . $resolution);
        }

        // symbol AS-IS (uppercase BTCIRT) — this endpoint does NOT use src/dst.
        $query = [
            'symbol'     => $symbol,
            'resolution' => $resolution,
            'to'         => $to,
        ];
        if ($from !== null) {
            $query['from'] = $from;
        }
        if ($countback !== null) {
            // Docs cap countback at 500 — clamp anything larger.
            $query['countback'] = min($countback, 500);
        }
        $query['page'] = $page;

        // Public, unsigned market-data read.
        $data = $this->request('GET', '/market/udf/history', $query);

        $status = (string) ($data['s'] ?? 'error');

        // no_data is a valid, non-error answer: empty candle set.
        if ($status === 'no_data') {
            return ['status' => 'no_data', 'candles' => [], 'errmsg' => null];
        }

        // error: surface as status 'error' with errmsg; do NOT throw.
        if ($status !== 'ok') {
            return [
                'status'  => 'error',
                'candles' => [],
                'errmsg'  => isset($data['errmsg']) ? (string) $data['errmsg'] : null,
            ];
        }

        // ok: zip the parallel t/o/h/l/c/v arrays into per-candle rows.
        $t = array_values((array) ($data['t'] ?? []));
        $o = array_values((array) ($data['o'] ?? []));
        $h = array_values((array) ($data['h'] ?? []));
        $l = array_values((array) ($data['l'] ?? []));
        $c = array_values((array) ($data['c'] ?? []));
        $v = array_values((array) ($data['v'] ?? []));

        $candles = [];
        foreach ($t as $i => $ts) {
            $candles[] = [
                't' => (int) $ts,
                // Preserve o/h/l/c/v as STRINGS (cast raw JSON scalars) to avoid
                // float precision loss on large IRT values.
                'o' => (string) ($o[$i] ?? ''),
                'h' => (string) ($h[$i] ?? ''),
                'l' => (string) ($l[$i] ?? ''),
                'c' => (string) ($c[$i] ?? ''),
                'v' => (string) ($v[$i] ?? ''),
            ];
        }

        return ['status' => 'ok', 'candles' => $candles, 'errmsg' => null];
    }

    /**
     * خلاصهٔ تمام موجودی‌ها (آرایه ساده) — برای نمایش سریع داشبورد/پنل
     */
    public function getBalances(): array
    {
        // Signed (Ed25519) authenticated read — POST /users/wallets/list with an
        // empty body, the exact call proven to return HTTP 200.
        $data = $this->request('POST', '/users/wallets/list', signed: true);
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
    public function placeOrder(string $symbol, string $side, int $price, string $quantity, ?string $clientRef = null): array
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

        if ($clientRef !== null) {
            // Official Nobitex field name for the client-supplied order tag.
            $payload['clientOrderId'] = $clientRef;
        }

        // Order-creating POST → non-idempotent, same policy as createOrder.
        // Ed25519-signed, same as createOrder: the exact JSON body is signed and sent.
        return $this->request('POST', '/market/orders/add', [], $payload, [], idempotentRetry: false, signed: true);
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
     *
     * Probes POST /users/wallets/list — the same authenticated endpoint that
     * getBalances() relies on. This genuinely reflects connectivity + auth:
     * a well-formed response with status=ok means the credentials work and the
     * API is reachable. The previous GET /users/profile probe false-alarmed
     * because that endpoint returns HTTP 400 {"code":"UnexpectedError"} on this
     * account even while wallet reads succeed perfectly.
     */
    public function healthCheck(): array
    {
        $startTime = microtime(true);
        $endpoint  = $this->baseUrl . '/users/wallets/list';

        try {
            // Same call getBalances() uses — signed (Ed25519) authenticated read.
            $data = $this->request('POST', '/users/wallets/list', signed: true);

            $responseTime = microtime(true) - $startTime;
            $isOk = is_array($data) && ($data['status'] ?? null) === 'ok';

            return [
                'ok'               => $isOk,
                'status'           => $isOk ? 'ok' : 'failed',
                'overall_status'   => $isOk ? 'healthy' : 'unhealthy',
                'response_time'    => $responseTime,
                'response_time_ms' => round($responseTime * 1000, 2),
                'mode'             => config('trading.grid.simulation', false) ? 'simulation' : 'live',
                'endpoint'         => $endpoint,
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
                'endpoint'         => $endpoint,
            ];
        }
    }
}
