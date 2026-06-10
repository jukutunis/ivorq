<?php

namespace Modules\Finance\Banking\Services;

use Exception;
use InvalidArgumentException;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Enums\BankStatementStatusEnum;
use Modules\Finance\Banking\Repositories\BankStatementRepository;

class BankStatementService
{
    public function __construct(
        protected BankStatementRepository $repository,
        protected BankStatementParserService $parserService
    ) {}

    public function create(array $data): BankStatement
    {
        if (!isset($data['opening_balance'])) {
            throw new InvalidArgumentException('Opening balance is required.');
        }

        if (!isset($data['imported_closing_balance'])) {
            throw new InvalidArgumentException('Imported closing balance is required.');
        }

        // BR-003: No overlapping statement date per account.
        if ($this->repository->existsForDate($data['bank_account_id'], $data['statement_date'], $data['property_id'])) {
            throw new Exception('A statement for this date already exists for the bank account.');
        }

        $data['status'] = BankStatementStatusEnum::Draft;

        return $this->repository->create($data);
    }

    public function import(BankStatement $statement, string $csvContent): BankStatement
    {
        // BR-009: Only Draft statements can be re-imported.
        if ($statement->status !== BankStatementStatusEnum::Draft) {
            throw new Exception('Only Draft statements can be imported or re-imported. This statement is ' . $statement->status->value);
        }

        $parsedData = $this->parserService->parseCsv($csvContent);

        \Illuminate\Support\Facades\DB::transaction(function () use ($statement, $parsedData) {
            // Delete existing lines if re-importing a Draft
            $statement->lines()->forceDelete();

            $calculatedClosingBalance = bcadd((string)$statement->opening_balance, '0', 2);

            foreach ($parsedData as $line) {
                // Determine uniqueness using DB index (BR-004)
                $statement->lines()->create([
                    'property_id' => $statement->property_id,
                    'transaction_date' => $line['transaction_date'],
                    'description' => $line['description'],
                    'reference' => $line['reference'] ?? null,
                    'amount' => $line['amount'],
                    'is_reconciled' => false,
                ]);

                // BR-007: System calculates closing balance.
                $calculatedClosingBalance = bcadd($calculatedClosingBalance, (string)$line['amount'], 2);
            }

            $statement->update([
                'closing_balance' => $calculatedClosingBalance,
                'status' => BankStatementStatusEnum::Imported, // BR-010: Locks immutability
            ]);
        });

        return $statement->fresh();
    }
}
