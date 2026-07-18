<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by the enforcing rate limiter when a permit could not be obtained
 * within the configured maximum wait window.
 *
 * This is deliberately a distinct type (not a generic RuntimeException) so
 * callers — and the retry classifier in NobitexService — can tell "we chose
 * not to send this request because the local budget is exhausted" apart from
 * a genuine transport/domain failure. It is NOT an Illuminate RequestException,
 * so isRetryable() classifies it as non-retryable: hitting the local gate
 * again on the very next attempt would just block and throw again.
 */
final class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limiterKey,
        public readonly int $maxWaitMs,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Rate limit gate exhausted for "%s": no permit became available within %dms.',
                $limiterKey,
                $maxWaitMs,
            ),
            0,
            $previous,
        );
    }
}
