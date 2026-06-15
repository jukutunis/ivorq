<?php

namespace Modules\Finance\Banking\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\DTOs\ParsedStatementDTO;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Enums\BankStatementStatusEnum;
use Modules\Finance\Banking\Exceptions\StatementImportException;
use Modules\Foundation\Property\Models\Property;

class StatementImportService
{
    /**
     * Import a parsed statement into the database.
     *
     * @param ParsedStatementDTO $dto
     * @param BankAccount $bankAccount
     * @param Property $property
     * @param string|null $fileName
     * @param string|null $fileHash
     * @param string|null $importedBy
     * @return BankStatement
     * @throws StatementImportException
     */
    public function import(
        ParsedStatementDTO $dto,
        BankAccount $bankAccount,
        Property $property,
        ?string $fileName = null,
        ?string $fileHash = null,
        ?string $importedBy = null
    ): BankStatement {
        if ($bankAccount->property_id !== $property->id) {
            throw StatementImportException::invalidProperty();
        }

        if (empty($dto->lines)) {
            throw StatementImportException::emptyStatement();
        }

        $rawStatementDate = $dto->statement_date ?? $dto->lines[count($dto->lines) - 1]->transaction_date;
        $statementDate = \Carbon\Carbon::parse($rawStatementDate)->format('Y-m-d');

        return DB::transaction(function () use ($dto, $bankAccount, $property, $fileName, $fileHash, $importedBy, $statementDate) {
            // 1. Statement Duplicate Detection
            // We consider a statement duplicate if one already exists for this bank account and date.
            $existingStatement = BankStatement::where('bank_account_id', $bankAccount->id)
                ->whereDate('statement_date', $statementDate)
                ->exists();

            if ($existingStatement) {
                throw StatementImportException::duplicateStatement($statementDate, $bankAccount->id);
            }

            // 2. Validate Balance
            // If opening and closing balances are provided, validate that opening + sum(lines) = closing
            $sumOfLines = array_sum(array_map(fn($l) => $l->amount, $dto->lines));
            
            if ($dto->opening_balance !== null && $dto->closing_balance !== null) {
                // Using round to 2 decimals to prevent floating point mismatch
                $expectedClosing = round($dto->opening_balance + $sumOfLines, 2);
                $actualClosing = round($dto->closing_balance, 2);

                if ($expectedClosing !== $actualClosing) {
                    throw StatementImportException::balanceMismatch($actualClosing, $expectedClosing);
                }
            }

            // 3. Create Statement
            $statement = BankStatement::create([
                'property_id' => $property->id,
                'bank_account_id' => $bankAccount->id,
                'statement_date' => $statementDate,
                'opening_balance' => $dto->opening_balance ?? 0.0,
                'closing_balance' => $dto->closing_balance ?? $sumOfLines, // Fallback if no closing balance provided
                'imported_closing_balance' => $dto->closing_balance,
                'status' => BankStatementStatusEnum::Imported->value,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'row_count' => count($dto->lines),
                'imported_by' => $importedBy,
                'imported_at' => now(),
            ]);

            // 4. Line Duplicate Detection and Insertion
            // Build an array of hashes for incoming lines to check internal file duplicates
            $hashes = [];

            foreach ($dto->lines as $lineDto) {
                // Priority: external_reference, Fallback: Composite hash
                if ($lineDto->external_reference) {
                    $idempotencyKey = $lineDto->external_reference;
                } else {
                    $idempotencyKey = hash('sha256', implode('|', [
                        $bankAccount->id,
                        $lineDto->transaction_date,
                        round($lineDto->amount, 2),
                        $lineDto->reference ?? ''
                    ]));
                }

                if (in_array($idempotencyKey, $hashes, true)) {
                    throw StatementImportException::duplicateLine($idempotencyKey);
                }
                $hashes[] = $idempotencyKey;

                // Check against database
                if ($lineDto->external_reference) {
                    $exists = BankStatementLine::whereHas('bankStatement', function ($q) use ($bankAccount) {
                            $q->where('bank_account_id', $bankAccount->id);
                        })
                        ->where('external_reference', $lineDto->external_reference)
                        ->exists();

                    if ($exists) {
                        throw StatementImportException::duplicateLine($lineDto->external_reference);
                    }
                } else {
                    // Check fallback composite hash equivalent in DB
                    $exists = BankStatementLine::whereHas('bankStatement', function ($q) use ($bankAccount) {
                            $q->where('bank_account_id', $bankAccount->id);
                        })
                        ->where('transaction_date', $lineDto->transaction_date)
                        ->where('amount', round($lineDto->amount, 2))
                        ->where('reference', $lineDto->reference)
                        ->exists();
                        
                    if ($exists) {
                        throw StatementImportException::duplicateLine($idempotencyKey);
                    }
                }

                BankStatementLine::create([
                    'property_id' => $property->id,
                    'bank_statement_id' => $statement->id,
                    'transaction_date' => $lineDto->transaction_date,
                    'description' => $lineDto->description,
                    'reference' => $lineDto->reference,
                    'external_reference' => $lineDto->external_reference ?? $idempotencyKey, // Save the generated composite hash as external ref if none provided
                    'amount' => $lineDto->amount,
                    'is_reconciled' => false,
                ]);
            }

            return $statement;
        });
    }
}
