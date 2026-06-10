<?php

namespace Modules\Finance\Banking\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Banking\Models\BankStatement;

class BankStatementRepository
{
    public function query(): Builder
    {
        return BankStatement::query();
    }

    public function find(string $id): ?BankStatement
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): BankStatement
    {
        return $this->query()->findOrFail($id);
    }

    public function create(array $data): BankStatement
    {
        return BankStatement::create($data);
    }

    public function update(string $id, array $data): bool
    {
        return BankStatement::where('id', $id)->update($data) > 0;
    }

    public function existsForDate(string $bankAccountId, string $date, string $propertyId): bool
    {
        return $this->query()
            ->where('bank_account_id', $bankAccountId)
            ->where('statement_date', $date)
            ->where('property_id', $propertyId)
            ->exists();
    }
}
