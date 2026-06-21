<?php

namespace Modules\Finance\GeneralLedger\Exceptions;

use Shared\Exceptions\BusinessLogicException;

class FinancialPeriodInvalidStateException extends BusinessLogicException
{
    public function __construct(string $message = 'Financial Period is in an unrecognized or invalid state.')
    {
        parent::__construct($message);
    }
}
