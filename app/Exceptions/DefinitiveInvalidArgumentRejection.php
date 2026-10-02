<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Definitive order rejection that used to surface as \InvalidArgumentException (same message). */
class DefinitiveInvalidArgumentRejection extends \InvalidArgumentException implements DefinitiveOrderRejection
{
    use CarriesOrderErrorCode;
}
