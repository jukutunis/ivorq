<?php

namespace Modules\Finance\CostControl\Services;

use Modules\Finance\CostControl\Repositories\CostLedgerRepository;
use Modules\Finance\CostControl\ValueObjects\CostLedgerEntryIntent;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use InvalidArgumentException;
use RuntimeException;

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

        if (!$sourceTransaction) {
            throw new InvalidArgumentException("Source InventoryTransaction not found.");
        }

        if ($sourceTransaction->property_id !== $intent->propertyId) {
            throw new InvalidArgumentException("Property scope mismatch between intent and source transaction.");
        }

        $existingEntry = $this->repository->findByIdempotency(
            $intent->propertyId,
            $intent->idempotencyKey,
            $intent->entrySequence
        );

        if ($existingEntry) {
            throw new RuntimeException("Duplicate idempotency detected. Controlled failure.");
        }

        return $this->repository->append($intent);
    }
}
