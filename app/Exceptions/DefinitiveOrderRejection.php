<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Marker for an order request the exchange DEFINITELY did not act on: Nobitex
 * answered {status: "failed", code: ...} with a validation/business code
 * (insufficient balance, below minimum, bad price, invalid pair, market
 * closed, trade limitation, parse error, …) or the request was refused
 * locally before anything was sent.
 *
 * Callers that place orders treat these as "no order exists": the intent row
 * becomes 'cancelled' with last_error_code — NEVER 'submission_unknown', which
 * is reserved for truly ambiguous failures (timeouts, 5xx, dropped responses;
 * see AmbiguousOrderSubmissionException).
 *
 * Implemented by subclasses of the SAME base exception types the codes used
 * to map to (RuntimeException / InvalidArgumentException / DomainException),
 * with the same messages, so every existing catch keeps working.
 */
interface DefinitiveOrderRejection extends \Throwable
{
    /** Exchange error code (e.g. 'InsufficientBalance') or 'LocalValidation'. */
    public function errorCode(): string;
}
