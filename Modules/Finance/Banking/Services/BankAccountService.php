<?php

namespace Modules\Finance\Banking\Services;

use InvalidArgumentException;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Repositories\BankAccountRepository;

class BankAccountService
{
    public function __construct(
        protected BankAccountRepository $repository
    ) {}

    public function create(array $data): BankAccount
    {
        if (!isset($data['opening_balance'])) {
            throw new InvalidArgumentException('Opening balance is required.');
        }

        if ($data['opening_balance'] < 0) {
            throw new InvalidArgumentException('Opening balance cannot be negative at creation.');
        }

        // BR-006: Reconciled Balance defaults to Opening Balance
        $data['current_balance'] = $data['opening_balance'];
        $data['reconciled_balance'] = $data['opening_balance'];

        return $this->repository->create($data);
    }

    public function update(BankAccount $bankAccount, array $data): BankAccount
    {
        // Don't allow updating balances directly through general update
        unset($data['opening_balance'], $data['current_balance'], $data['reconciled_balance']);
        
        $this->repository->update($bankAccount->id, $data);
        return $bankAccount->fresh();
    }

    public function delete(BankAccount $bankAccount): bool
    {
        return $this->repository->delete($bankAccount->id);
    }
}
