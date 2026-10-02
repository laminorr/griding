<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Definitive order rejection that used to surface as a plain \RuntimeException (same message). */
class DefinitiveRuntimeRejection extends \RuntimeException implements DefinitiveOrderRejection
{
    use CarriesOrderErrorCode;
}
