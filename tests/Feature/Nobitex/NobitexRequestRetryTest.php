<?php

declare(strict_types=1);

namespace Tests\Feature\Nobitex;

use App\Services\NobitexService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Phase 12 Step 1 — behaviour lock-in for NobitexService::request().
 *
 * These tests pin the CURRENT retry/error-mapping behaviour of the unified
 * REST entrypoint (App\Services\NobitexService::request(), NobitexService.php
 * :88-150) EXACTLY as it exists today — including its known bugs. When Phase 12
 * Steps 2-7 change the retry predicate, the diffs to these assertions become
 * the visible, reviewable record of each behavioural change.
 *
 * Retry predicate under test (isRetryable(), :146-150):
 *     Str::contains($msg, ['429', '5', 'timeout', 'cURL error', 'timed out'])
 * Note the bare substring '5': ANY exception message containing the digit 5 is
 * currently treated as retryable. Several tests below exist purely to document
 * the consequences of that.
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
     * 6. KNOWN BUG (Phase 12 Step 2 will flip this).
     *
     * A 400-class error whose body carries a price like "150000" is retried,
     * even though a 4xx client error is not transient. The cause is the bare
     * '5' in isRetryable()'s substring list (:149): the RequestException
     * message embeds the response body, that body contains "150000", and
     * Str::contains(..., ['5', ...]) matches. Here the first 400 is therefore
     * retried and the second (200) response is what actually succeeds — proof
     * the retry happened.
     *
     * Step 2 will replace the substring predicate with a status/exception-type
     * check; when it lands, this test must change to assert a single attempt
     * and a thrown error. Renaming it away from test_known_bug_* is the signal
     * that the bug is gone.
     */
    public function test_known_bug_domain_error_containing_digit_5_is_retried(): void
    {
        Http::fake([self::HOST => Http::sequence()
            ->push(['status' => 'failed', 'code' => 'BadPrice', 'message' => 'Order rejected: price 150000 invalid'], 400)
            ->push(['status' => 'ok', 'order' => ['id' => 444]], 200),
        ]);

        $data = $this->invokeRequest();

        $this->assertSame('ok', $data['status']);
        Http::assertSentCount(2); // the 400 was (wrongly) retried
    }

    /**
     * 7. DuplicateOrder domain failure → mapped to the domain RuntimeException
     *    (throwDomainError(), :193) and NOT retried.
     *
     * IMPORTANT / load-bearing accident: this currently works ONLY because the
     * mapped message "Duplicate order in last 10s" happens to contain no digit
     * '5' and none of the other trigger words, so isRetryable() returns false.
     * If that human-readable string ever gained a '5', the current logic would
     * silently start retrying a duplicate-order rejection. Phase 12 Step 2
     * removes this fragility by keying retry on status/type rather than message
     * text. A single attempt below proves it is not retried today.
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
     * 8. KNOWN GAP (Phase 12 Step 2 will flip this).
     *
     * A 408 Request Timeout is a genuinely transient condition that SHOULD be
     * retried, but currently is not: the RequestException message is only
     * "HTTP request returned status code 408" — it carries neither a '5' nor
     * the literal word "timeout", so isRetryable() returns false. Step 2's
     * status-aware predicate will add 408 to the retry set; when it does, this
     * test must change to assert multiple attempts. The empty body keeps any
     * accidental trigger characters out of the message.
     */
    public function test_known_bug_408_is_not_retried(): void
    {
        Http::fake([self::HOST => Http::response('', 408)]);

        $threw = false;
        try {
            $this->invokeRequest();
        } catch (Throwable $e) {
            $threw = true;
            $this->assertInstanceOf(RequestException::class, $e);
        }

        $this->assertTrue($threw);
        Http::assertSentCount(1); // NOT retried today
    }

    /** 9. Non-JSON 200 body → BadResponse RuntimeException (not retried). */
    public function test_non_json_200_body_throws_bad_response(): void
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
        Http::assertSentCount(1);
    }
}
