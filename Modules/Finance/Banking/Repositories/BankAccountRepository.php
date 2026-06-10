<?php

namespace Modules\Finance\Banking\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Banking\Models\BankAccount;

class BankAccountRepository
{
    public function query(): Builder
    {
        return BankAccount::query();
    }

    public function find(string $id): ?BankAccount
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): BankAccount
    {
        return $this->query()->findOrFail($id);
    }

    public function create(array $data): BankAccount
    {
        return BankAccount::create($data);
    }

    public function update(string $id, array $data): bool
    {
        return BankAccount::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return BankAccount::where('id', $id)->delete() > 0;
    }
}
