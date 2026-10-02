<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Nobitex refused an order because an OPEN order with the same clientOrderId
 * already exists (code 'DuplicateClientOrderId' / margin docs:
 * 'duplicateClientOrderId').
 *
 * Every intent row has its own id (GridOrder::clientOrderIdFor) and a retry of
 * the same intent resends that same id, so a duplicate can only mean "this
 * very intent was already accepted" — e.g. an earlier attempt whose response
 * was lost. It is therefore deliberately NOT a DefinitiveOrderRejection: the
 * order may well exist. Order-placing callers park the row as
 * 'submission_unknown' (the exchange call was attempted) and the
 * SubmissionReconciler resolves it by clientOrderId lookup. Callers must never
 * answer it with a new id or a re-send under a different id.
 *
 * Extends RuntimeException so existing catch-sites keep working unchanged.
 */
class DuplicateClientOrderIdException extends \RuntimeException
{
}
