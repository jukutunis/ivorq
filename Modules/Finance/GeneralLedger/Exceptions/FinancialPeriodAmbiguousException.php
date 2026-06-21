<?php

namespace Modules\Finance\GeneralLedger\Exceptions;

use Shared\Exceptions\BusinessLogicException;

class FinancialPeriodAmbiguousException extends BusinessLogicException
{
    public function __construct(string $message = 'More than one active Financial Period matches the current Business Date in the current Property scope.')
    {
        parent::__construct($message);
    }
}
