<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Nobitex code 'InsufficientBalance' — a distinct type so the exit-sell
 * self-heal can recognise it without message sniffing. Still a
 * RuntimeException with the historic message 'Insufficient balance'.
 */
class InsufficientBalanceRejection extends DefinitiveRuntimeRejection
{
}
