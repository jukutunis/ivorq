<?php

namespace Modules\Finance\GeneralLedger\Repositories;

use Modules\Finance\GeneralLedger\Models\Account;

class AccountRepository
{
    public function create(array $data): Account
    {
        return Account::create($data);
    }

    public function findByIdAndProperty(string $id, string $propertyId): ?Account
    {
        return Account::where('id', $id)->where('property_id', $propertyId)->first();
    }
}
