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
        private readonly InventoryStockRepository $stockRepo
    ) {
    }

    public function post(InventoryLedgerPostingIntent $intent): InventoryTransaction
    {
        return $this->executeOnce(function () use ($intent) {
            return DB::transaction(function () use ($intent) {
                $existing = InventoryTransaction::where('property_id', $intent->propertyId)
                    ->where('idempotency_key', $intent->idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $isMatch = $existing->source_document_type === $intent->sourceDocumentType &&
                               $existing->source_document_id === $intent->sourceDocumentId &&
                               $existing->source_line_type === $intent->sourceLineType &&
                               $existing->source_line_id === $intent->sourceLineId &&
                               $existing->movement_role === $intent->movementRole &&
                               $existing->item_id === $intent->itemId &&
                               $existing->location_id === $intent->locationId &&
                               (string)$existing->quantity_change === (string)$intent->quantityChange;

                    if ($isMatch) {
                        return $existing;
                    }

                    throw new RuntimeException("Idempotency collision: same key with different intent.");
                }

                $businessDate = PropertyBusinessDate::where('property_id', $intent->propertyId)
                    ->where('business_date', $intent->businessDate)
                    ->lockForUpdate()
                    ->first();

                if (!$businessDate || $businessDate->status !== PropertyBusinessDateStatusEnum::Open || !$businessDate->is_open) {
                    throw new RuntimeException("Business date is closed or missing.");
                }

                $year = $intent->occurredAt->year;
                $month = $intent->occurredAt->month;

                $period = FinancialPeriod::where('property_id', $intent->propertyId)
                    ->where('period_year', $year)
                    ->where('period_month', $month)
                    ->lockForUpdate()
                    ->first();

                if (!$period || ($period->status !== FinancialPeriodStatusEnum::Open && $period->status !== FinancialPeriodStatusEnum::Reopened)) {
                    throw new RuntimeException("Financial period is closed or missing.");
                }

                $stock = $this->stockRepo->createOrLockControlled($intent->propertyId, $intent->itemId, $intent->locationId);

                if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || !$businessDate->is_open) {
                    throw new RuntimeException("Business date became closed during lock acquisition.");
                }

                if ($period->status !== FinancialPeriodStatusEnum::Open && $period->status !== FinancialPeriodStatusEnum::Reopened) {
                    throw new RuntimeException("Financial period became closed during lock acquisition.");
                }

                $quantityBefore = (string)$stock->physical_quantity;
                $quantityAfter = bcadd($quantityBefore, (string)$intent->quantityChange, 4);

                $transaction = $this->transactionRepo->appendControlled($intent, $quantityBefore, $quantityAfter);

                $status = (bccomp($quantityAfter, '0', 4) > 0) ? ItemStatusEnum::InStock : ItemStatusEnum::OutOfStock;

                $this->stockRepo->updateBalance($stock->id, $quantityAfter, $status, $intent->occurredAt);

                return $transaction;
            });
        });
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
