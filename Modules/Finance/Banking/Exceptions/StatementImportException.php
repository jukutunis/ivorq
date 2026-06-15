<?php

namespace Modules\Finance\Banking\Exceptions;

use Exception;

class StatementImportException extends Exception
{
    public static function duplicateStatement(string $date, string $bankAccountId): self
    {
        return new self("Duplicate statement import detected for date {$date} and bank account {$bankAccountId}.");
    }

    public static function duplicateLine(string $reference): self
    {
        return new self("Duplicate statement line detected with reference {$reference}.");
    }

    public static function balanceMismatch(float $expected, float $actual): self
    {
        return new self("Balance mismatch. Expected closing balance {$expected}, but lines sum to {$actual}.");
    }

    public static function invalidProperty(): self
    {
        return new self("Bank account does not belong to the active property.");
    }

    public static function emptyStatement(): self
    {
        return new self("Cannot import an empty bank statement.");
    }
}
