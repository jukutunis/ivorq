<?php

namespace Modules\Finance\Banking\Exceptions;

use Exception;

class ReconciliationCommitException extends Exception
{
    public static function overAllocation(string $type, float $requested, float $allowed): self
    {
        return new self("Over Allocation Error on {$type}: Cannot allocate {$requested} because it exceeds the allowed balance of {$allowed}.");
    }

    public static function alreadyReconciled(string $entity): self
    {
        return new self("Immutability Error: {$entity} is already fully reconciled and cannot be modified or rematched.");
    }

    public static function invalidAmount(float $amount): self
    {
        return new self("Invalid amount {$amount}. Amount must be greater than zero.");
    }
}
