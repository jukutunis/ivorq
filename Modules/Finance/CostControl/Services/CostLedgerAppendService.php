<?php

namespace Modules\Finance\CostControl\Services;

use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;

class CostLedgerAppendService
{
    private CostLedgerRepository $repository;

    private InventoryTransactionRepository $transactionRepository;

    public function __construct(
        CostLedgerRepository $repository,
        InventoryTransactionRepository $transactionRepository
    ) {
        $this->repository = $repository;
        $this->transactionRepository = $transactionRepository;
    }

    public function append(CostLedgerEntryIntent $intent): CostLedgerEntry
    {
        $sourceTransaction = $this->transactionRepository->findById($intent->sourceInventoryTransactionId);

        if (! $sourceTransaction) {
            throw new InvalidArgumentException('Source InventoryTransaction not found.');
        }

        if ($sourceTransaction->property_id !== $intent->propertyId) {
            throw new InvalidArgumentException('Property scope mismatch between intent and source transaction.');
        }

        return $this->repository->append($intent);
    }
}
