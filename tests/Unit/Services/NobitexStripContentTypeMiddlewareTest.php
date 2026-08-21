<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NobitexService;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * PART 2b — the bodyless signed request must go out with NO Content-Type header.
 *
 * Nobitex returns HTTP 400 for a bodyless signed POST that carries ANY
 * Content-Type (proven on host with isolated cURL: no Content-Type → 200;
 * application/json OR x-www-form-urlencoded → 400). Laravel's Http client always
 * attaches a Content-Type and withHeaders(['Content-Type'=>null]) does not remove
 * it, so the fix is a Guzzle middleware (applied AFTER Guzzle's prepare_body) that
 * strips Content-Type right before the request hits the wire.
 *
 * This effect is invisible to Http::fake(), which records the request one stage
 * earlier — before user middleware runs — so we exercise the middleware directly
 * against a PSR-7 request to lock the behaviour.
 */
final class NobitexStripContentTypeMiddlewareTest extends TestCase
{
    public function test_middleware_strips_content_type_from_the_request(): void
    {
        $seen = null;

        // Inner handler: capture the request the middleware forwards, and satisfy
        // the Guzzle middleware contract by returning a PromiseInterface.
        $inner = function (RequestInterface $request, array $options) use (&$seen) {
            $seen = $request;

            return new FulfilledPromise(new Psr7Response(200));
        };

        $middleware = NobitexService::stripContentTypeMiddleware();
        $wrapped = $middleware($inner);

        $request = new Psr7Request(
            'POST',
            'https://apiv2.nobitex.ir/users/wallets/list',
            ['Content-Type' => 'application/json', 'Nobitex-Key' => 'pub'],
            '' // empty body — the proven bodyless shape
        );

        $this->assertTrue($request->hasHeader('Content-Type'), 'precondition: request starts with a Content-Type');

        $wrapped($request, []);

        $this->assertInstanceOf(RequestInterface::class, $seen);
        $this->assertFalse($seen->hasHeader('Content-Type'), 'middleware must remove Content-Type before the request hits the wire');
        // Other headers are untouched — only Content-Type is stripped.
        $this->assertSame('pub', $seen->getHeaderLine('Nobitex-Key'));
    }
}
