<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by NobitexService::request() when an order-creating (non-idempotent)
 * POST fails in a way whose outcome cannot be known — a transport-level
 * ConnectionException (cURL 28: connect- and read-timeouts are
 * indistinguishable, so the order MAY already sit on the book) or a
 * server-side 408/5xx (the exchange may have created the order before the
 * error). In every such case a blind retry risks placing the SAME order twice
 * with real money, so request() refuses to retry and surfaces this instead.
 *
 * It deliberately extends RuntimeException (a \Throwable) so it flows straight
 * into GridOrderExecutor::applyForBot()'s existing `catch (\Throwable)` guard,
 * which — because the exchange call WAS attempted — parks the intent row in
 * 'submission_unknown' for reconciliation rather than 'cancelled'. The original
 * failure is preserved as the previous exception for logging/forensics.
 */
final class AmbiguousOrderSubmissionException extends RuntimeException
{
    public function __construct(
        public readonly string $endpoint,
        public readonly ?string $clientRef,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Ambiguous order submission on POST %s%s: retry suppressed because the exchange may already hold this order (%s). Reconcile before resubmitting.',
                $endpoint,
                $clientRef !== null ? sprintf(' (client_ref=%s)', $clientRef) : '',
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
