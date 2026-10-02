<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Definitive order rejection that used to surface as \DomainException (same message). */
class DefinitiveDomainRejection extends \DomainException implements DefinitiveOrderRejection
{
    use CarriesOrderErrorCode;
}
