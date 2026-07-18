<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Phase 12 — behaviour tests for NobitexService::request().
 *
 * Step 1 pinned the ORIGINAL retry/error-mapping behaviour, including three
 * named known-bug tests. Step 2 replaced the fragile substring predicate with
 * status/type-based classification and taught the retry path to honour a 429
 * Retry-After header; the flips and additions below are the reviewable record
 * of that change.
 *
 * Retry predicate under test (isRetryable()):
 *     - Illuminate\Http\Client\RequestException  → retryable iff the real HTTP
 *       status is in config('trading.nobitex.retry.http_statuses'), which now
 *       ships {408, 429, 500, 502, 503, 504}.
 *     - Domain exceptions from throwDomainError() and the BadResponse
 *       RuntimeException are NOT RequestExceptions → never retried.
 *     - ConnectionException is caught earlier in the loop and always retried
 *       in this step.
 * Message text is no longer consulted, so a 4xx body echoing a price like
 * "150000" can no longer trigger a retry.
 *
 * Backoff (computeSleepMs()): a 429 with a numeric Retry-After (seconds) sleeps
 * per the header, capped by config retry.max_ms; every other retryable case
 * uses the exponential-backoff-with-jitter formula.
 *
 * request() is protected, so it is invoked directly via reflection. That keeps
 * the harness focused on the retry loop itself, without the DTO-mapping of the
 * public methods (createOrder/getBalance/...) getting in the way.
 */
final class NobitexRequestRetryTest extends TestCase
{
    private const HOST = 'apiv2.nobitex.ir/*';

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the retry config for determinism and speed. request() reads these
        // in the constructor, so they must be set BEFORE the service is built
        // (see makeService()). sleep=0 keeps the suite fast (the only remaining
        // delay is the hard-coded 0-250ms jitter in sleepBackoff()).
        config([
            'trading.nobitex.base_url'        => 'https://apiv2.nobitex.ir',
            'trading.nobitex.api_key'         => '',
            'trading.nobitex.retry.times'     => 3,
            'trading.nobitex.retry.sleep'     => 0,
            'trading.nobitex.rate_limit.rpm'  => 1000,
        ]);
    }

    /**
     * Build the service AFTER config is pinned, then expose the protected
     * request() method. All tests drive a POST to /market/orders/add — the
     * concrete endpoint does not matter, only the retry/mapping behaviour.
     *
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function invokeRequest(string $method = 'POST', string $path = '/market/orders/add', array $json = ['x' => 1]): array
    {
        $svc = new NobitexService();
        $ref = new ReflectionMethod($svc, 'request');
        $ref->setAccessible(true);

        return $ref->invoke($svc, $method, $path, [], $json);
    }

    /** 1. Transient 500 then 200 → retried, succeeds on attempt 2. */
    public function test_500_then_200_is_retried_and_succeeds(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['error' => 'server boom'], 500)
            ->push(['status' => 'ok', 'order' => ['id' => 111]], 200),
        ]);

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        Http::assertSentCount(2);
    }

    /** 2. 429 rate-limit then 200 → retried, succeeds on attempt 2. */
    public function test_429_then_200_is_retried_and_succeeds(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(['status' => 'ok', 'order' => ['id' => 222]], 200),
        ]);

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        Http::assertSentCount(2);
    }

    /** 3. ConnectionException then 200 → always retried (:135), succeeds. */
    public function test_connection_exception_then_200_is_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Connection timed out');
            }

            return Http::response(['status' => 'ok', 'order' => ['id' => 333]], 200);
        });

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        $this->assertSame(2, $calls, 'ConnectionException must be retried exactly once before success.');
    }

    /** 4. Persistent 500 → throws after EXACTLY 3 attempts (retryTimes=3). */
    public function test_persistent_500_throws_after_exactly_three_attempts(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['error' => 'boom'], 500)
            ->push(['error' => 'boom'], 500),
        ]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (Throwable $e) {
            $threw = true;
            $this->assertInstanceOf(RequestException::class, $e);
        }

        $this->assertTrue($threw, 'A persistent 500 must ultimately throw.');
        Http::assertSentCount(3);
    }

    /**
     * 5. Plain 400 whose body contains NO digit '5' and none of the trigger
     *    words → NOT retryable → thrown on the first attempt.
     */
    public function test_plain_400_without_trigger_chars_is_not_retried(): void
    {
        Http::fake([self::HOST => Http::response(['error' => 'bad input'], 400)]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (Throwable $e) {
            $threw = true;
            $this->assertInstanceOf(RequestException::class, $e);
        }

        $this->assertTrue($threw);
        Http::assertSentCount(1);
    }

    /**
     * 6. FIXED (was test_known_bug_domain_error_containing_digit_5_is_retried).
     *
     * A 400-class error whose body carries a price like "150000" must NOT be
     * retried. Under the old substring predicate the bare '5' matched the
     * "150000" embedded in the RequestException message and wrongly triggered a
     * retry. Step 2's status-based classifier keys on the real HTTP status
     * (400 ∉ retry set), so message digits are irrelevant: a single attempt is
     * made and the RequestException is thrown.
     */
    public function test_domain_error_with_digit_5_is_not_retried(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['status' => 'failed', 'code' => 'BadPrice', 'message' => 'Order rejected: price 150000 invalid'], 400)
            ->push(['status' => 'ok', 'order' => ['id' => 444]], 200),
        ]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (Throwable $e) {
            $threw = true;
            $this->assertInstanceOf(RequestException::class, $e);
        }

        $this->assertTrue($threw, 'A 400 must surface as a thrown RequestException.');
        Http::assertSentCount(1); // the 400 is no longer retried
    }

    /**
     * 7. DuplicateOrder domain failure → mapped to the domain RuntimeException
     *    (throwDomainError()) and NOT retried.
     *
     * Non-retry is now BY DESIGN, not by accident. throwDomainError() raises a
     * plain RuntimeException, which is not a RequestException, so isRetryable()
     * classifies it as non-retryable regardless of its message text. The mapped
     * string could gain a '5' tomorrow and the behaviour would not change — the
     * old load-bearing dependence on the message lacking a '5' is gone. A single
     * attempt below proves it is not retried.
     */
    public function test_duplicate_order_is_mapped_and_not_retried(): void
    {
        Http::fake([self::HOST => Http::response([
            'status'  => 'failed',
            'code'    => 'DuplicateOrder',
            'message' => 'Duplicate order in last 10s',
        ], 200)]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertSame('Duplicate order in last 10s', $e->getMessage());
        }

        $this->assertTrue($threw, 'DuplicateOrder must surface as a domain RuntimeException.');
        Http::assertSentCount(1); // not retried
    }

    /**
     * 8. FIXED (was test_known_bug_408_is_not_retried).
     *
     * A 408 Request Timeout is a genuinely transient condition and is now
     * retried: config('trading.nobitex.retry.http_statuses') includes 408, so
     * the status-aware predicate treats it like the 5xx/429 set. Here a first
     * 408 is retried and the following 200 succeeds — proof the retry happened.
     * The empty 408 body also guards against message-text sniffing sneaking
     * back in: there is no '5' or "timeout" to accidentally match.
     */
    public function test_408_is_retried(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push('', 408)
            ->push(['status' => 'ok', 'order' => ['id' => 408]], 200),
        ]);

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        Http::assertSentCount(2); // the 408 is now retried
    }

    /**
     * 9. Non-JSON 200 body → BadResponse RuntimeException, NOT retried.
     *
     * BadResponse is deliberately classified as non-retryable. It is only
     * reached after $res->failed() has already returned false — i.e. the server
     * answered with a 2xx status but a malformed body — so retrying the same
     * request (a POST could double-place an order) will not help. Genuinely
     * transient gateway failures (e.g. a Cloudflare 5xx HTML error page) carry a
     * 5xx status and are thrown as RequestExceptions by $res->throw() long
     * before this branch, so they remain retryable via the status path. The
     * single attempt below pins the non-retry.
     */
    public function test_non_json_200_body_throws_bad_response_and_is_not_retried(): void
    {
        Http::fake([self::HOST => Http::response('this is not json', 200)]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('BadResponse', $e->getMessage());
        }

        $this->assertTrue($threw, 'A non-JSON 200 body must raise BadResponse.');
        Http::assertSentCount(1); // BadResponse is not retried, by design
    }

    /*
     |------------------------------------------------------------------
     | Phase 12 Step 2 additions — Retry-After honouring on 429.
     |
     | These drive computeSleepMs() directly (a protected seam) so the chosen
     | delay is asserted WITHOUT actually sleeping. The retry config is pinned
     | to sleep=0 in setUp(), so the formula fallback collapses to just the
     | 0-250ms jitter.
     |------------------------------------------------------------------
     */

    /**
     * Invoke the protected computeSleepMs() seam with a synthetic exception.
     */
    private function invokeComputeSleepMs(int $attemptIndex, int $baseSleepMs, ?Throwable $e): int
    {
        $svc = new NobitexService();
        $ref = new ReflectionMethod($svc, 'computeSleepMs');
        $ref->setAccessible(true);

        return (int) $ref->invoke($svc, $attemptIndex, $baseSleepMs, $e);
    }

    /** Build a RequestException wrapping a status + headers, for the seam tests. */
    private function requestException(int $status, array $headers = []): RequestException
    {
        return new RequestException(new ClientResponse(new Psr7Response($status, $headers)));
    }

    /**
     * 10. 429 + Retry-After: 3 → sleep honours the header (3s = 3000ms), and a
     *     huge Retry-After is capped at config retry.max_ms.
     */
    public function test_429_retry_after_header_is_honoured_and_capped(): void
    {
        config(['trading.nobitex.retry.max_ms' => 4000]);

        // Header value in seconds → milliseconds, under the cap.
        $ms = $this->invokeComputeSleepMs(0, 0, $this->requestException(429, ['Retry-After' => '3']));
        $this->assertSame(3000, $ms, 'Retry-After: 3 must sleep 3000ms.');

        // A large header value is clamped to max_ms.
        $capped = $this->invokeComputeSleepMs(0, 0, $this->requestException(429, ['Retry-After' => '99']));
        $this->assertSame(4000, $capped, 'Retry-After above the cap must clamp to retry.max_ms.');
    }

    /**
     * 11. 429 WITHOUT a Retry-After header → falls back to the formula backoff.
     *     With sleep pinned to 0 and attempt index 0, that is only the jitter,
     *     i.e. a value in [0, 250]; it never picks up any header-derived delay.
     */
    public function test_429_without_retry_after_falls_back_to_formula(): void
    {
        $ms = $this->invokeComputeSleepMs(0, 0, $this->requestException(429));

        $this->assertGreaterThanOrEqual(0, $ms);
        $this->assertLessThanOrEqual(250, $ms, 'No Retry-After ⇒ formula backoff (base 0 + jitter ≤ 250).');
    }
}
