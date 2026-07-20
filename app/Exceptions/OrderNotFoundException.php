<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Nobitex answered a lookup with code 'NotFound' — the queried resource
 * definitively does not exist for this account.
 *
 * Extends RuntimeException so every existing catch-site that handled the old
 * `new \RuntimeException('Requested resource not found')` mapping in
 * NobitexService::throwDomainError() keeps working unchanged. The dedicated
 * type exists for the Phase 12 Step 7 reconciler, which must distinguish "the
 * exchange says this order does not exist" (a definitive absence signal) from
 * every other failure (ambiguous — resolve nothing) without sniffing message
 * strings.
 */
class OrderNotFoundException extends \RuntimeException
{
}
