<?php

namespace Modules\Finance\GeneralLedger\Exceptions;

use Shared\Exceptions\BusinessLogicException;

class FinancialPeriodMissingException extends BusinessLogicException
{
    public function __construct(string $message = 'Active Financial Period is missing in the current Property scope.')
    {
        parent::__construct($message);
    }
}
