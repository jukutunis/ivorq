<?php

namespace Modules\Finance\GeneralLedger\Exceptions;

use Exception;

class JournalCandidateBalanceException extends Exception
{
    // Thrown when total_debit != total_credit
}
