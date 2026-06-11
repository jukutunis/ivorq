<?php

namespace Modules\Finance\GeneralLedger\Exceptions;

use Exception;

class PeriodClosedException extends Exception
{
    public function __construct(string $message = "The financial period is closed. No modifications are allowed.")
    {
        parent::__construct($message);
    }
}
