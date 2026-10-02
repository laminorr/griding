<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Shared storage for an exchange order error code (DefinitiveOrderRejection). */
trait CarriesOrderErrorCode
{
    private string $orderErrorCode = 'Unknown';

    public static function withCode(string $code, string $message): static
    {
        $e = new static($message);
        $e->orderErrorCode = $code;
        return $e;
    }

    public function errorCode(): string
    {
        return $this->orderErrorCode;
    }
}
