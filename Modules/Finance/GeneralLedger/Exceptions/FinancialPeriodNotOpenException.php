<?php

namespace Modules\Finance\GeneralLedger\Exceptions;

use Shared\Exceptions\BusinessLogicException;

class FinancialPeriodNotOpenException extends BusinessLogicException
{
    public function __construct(string $message = 'Financial Period is not open.')
    {
        parent::__construct($message);
    }
}
