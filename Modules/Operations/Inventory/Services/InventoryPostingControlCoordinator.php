<?php

namespace Modules\Operations\Inventory\Services;

use Throwable;
use Modules\Operations\Inventory\Exceptions\InventoryPostingRetryableException;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;

class InventoryPostingControlCoordinator
{
    private const RETRYABLE_SQLSTATES = [
        '40P01' => 'DEADLOCK_DETECTED',
        '40001' => 'SERIALIZATION_FAILURE',
        '55P03' => 'LOCK_TIMEOUT',
        '57014' => 'STATEMENT_TIMEOUT',
    ];

    public function __construct(
        private readonly InventoryTransactionRepository $transactionRepo,
        private readonly InventoryStockRepository $stockRepo,
        private readonly OutboxRepository $outboxRepository
    ) {
    }

    public function post(
        InventoryLedgerPostingIntent $intent,
        ?string $actorId = null
    ): InventoryTransaction {
        return $this->executeOnce(function () use ($intent, $actorId) {
            return DB::transaction(function () use ($intent, $actorId) {
                $existing = InventoryTransaction::where('property_id', $intent->propertyId)
                    ->where('idempotency_key', $intent->idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $isMatch = $existing->property_id === $intent->propertyId &&
                               $existing->source_document_type === $intent->sourceDocumentType &&
                               $existing->source_document_id === $intent->sourceDocumentId &&
                               $existing->source_line_type === $intent->sourceLineType &&
                               $existing->source_line_id === $intent->sourceLineId &&
                               $existing->movement_role === $intent->movementRole &&
                               $existing->item_id === $intent->itemId &&
                               $existing->location_id === $intent->locationId &&
                               bccomp((string)$existing->quantity_change, (string)$intent->quantityChange, 4) === 0 &&
                               bccomp((string)$existing->unit_cost, (string)$intent->unitCost, 4) === 0 &&
                               bccomp((string)$existing->total_cost, (string)$intent->totalCost, 4) === 0;

                    if ($isMatch) {
                        return $existing;
                    }

                    throw new RuntimeException(
                        "Idempotency collision: same key with different intent. Key: {$intent->idempotencyKey}"
                    );
                }

                [$businessDate, $period] = $this->lockContext($intent->propertyId, $intent->businessDate, $intent->occurredAt);

                $stock = $this->stockRepo->createOrLockControlled($intent->propertyId, $intent->itemId, $intent->locationId);

                if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || !$businessDate->is_open) {
                    throw new RuntimeException("Business date became closed during lock acquisition.");
                }

                if ($period->status !== FinancialPeriodStatusEnum::Open && $period->status !== FinancialPeriodStatusEnum::Reopened) {
                    throw new RuntimeException("Financial period became closed during lock acquisition.");
                }

                $quantityBefore = (string)$stock->physical_quantity;
                $quantityAfter = bcadd($quantityBefore, (string)$intent->quantityChange, 4);

                if (bccomp($quantityAfter, '0', 4) < 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'stock' => ["Negative stock is not allowed for item {$intent->itemId} at location {$intent->locationId}"],
                    ]);
                }

                $transaction = $this->transactionRepo->appendControlled($intent, $quantityBefore, $quantityAfter, $actorId);

                $this->outboxRepository->createPending([
                    'topic' => 'inventory.transaction.posted',
                    'source_inventory_transaction_id' => $transaction->id,
                    'payload' => ['transactionId' => $transaction->id],
                    'idempotency_key' => "inventory_transaction:{$transaction->id}:cost_ledger",
                ]);

                $status = (bccomp($quantityAfter, '0', 4) > 0) ? ItemStatusEnum::InStock : ItemStatusEnum::OutOfStock;

                $this->stockRepo->updateBalance($stock->id, $quantityAfter, $status, $intent->occurredAt);

                return $transaction;
            });
        });
    }

    public function lockContext(string $propertyId, string $businessDate, \Illuminate\Support\Carbon $occurredAt): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Controlled inventory posting lock context requires an active transaction.'
            );
        }

        $dbDate = PropertyBusinessDate::where('property_id', $propertyId)
            ->where('business_date', $businessDate)
            ->lockForUpdate()
            ->first();

        if (!$dbDate || $dbDate->status !== PropertyBusinessDateStatusEnum::Open || !$dbDate->is_open) {
            throw new RuntimeException("Business date is closed or missing.");
        }

        $year = $occurredAt->year;
        $month = $occurredAt->month;

        $period = FinancialPeriod::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->first();

        if (!$period || ($period->status !== FinancialPeriodStatusEnum::Open && $period->status !== FinancialPeriodStatusEnum::Reopened)) {
            throw new RuntimeException("Financial period is closed or missing.");
        }

        return [$dbDate, $period];
    }

    public function executeOnce(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    private function handleFailure(Throwable $e): void
    {
        $current = $e;

        while ($current !== null) {
            $sqlState = $this->extractSqlState($current);

            if ($sqlState !== null && array_key_exists($sqlState, self::RETRYABLE_SQLSTATES)) {
                $reasonCode = self::RETRYABLE_SQLSTATES[$sqlState];
                throw new InventoryPostingRetryableException($reasonCode, $e);
            }

            $current = $current->getPrevious();
        }

        throw $e;
    }

    private function extractSqlState(Throwable $e): ?string
    {
        if (isset($e->errorInfo) && is_array($e->errorInfo) && isset($e->errorInfo[0])) {
            return (string) $e->errorInfo[0];
        }

        $code = $e->getCode();
        if (is_string($code) || is_numeric($code)) {
            return (string) $code;
        }

        return null;
    }
}
