<?php

namespace Modules\Operations\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Outbox\Repositories\OutboxRepository;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Exceptions\InventoryPostingRetryableException;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransactionRepository;
use Modules\Operations\Inventory\Repositories\InventoryValuationSequenceRepository;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;
use RuntimeException;
use Throwable;

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
        private readonly OutboxRepository $outboxRepository,
        private readonly InventoryValuationSequenceRepository $sequenceRepo,
        private readonly CostDeliveryModePort $costDeliveryModePort,
    ) {}

    public function post(
        InventoryLedgerPostingIntent $intent,
        ?string $actorId = null
    ): InventoryTransaction {
        return $this->executeOnce(function () use ($intent, $actorId) {
            [$valuationApprovalStatus, $valuationApprovalReference] = $this->resolveValuationAuthorizationEvidence($intent);

            return DB::transaction(function () use ($intent, $actorId, $valuationApprovalStatus, $valuationApprovalReference) {
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
                               bccomp((string) $existing->quantity_change, (string) $intent->quantityChange, 4) === 0 &&
                               bccomp((string) $existing->unit_cost, (string) $intent->unitCost, 4) === 0 &&
                               bccomp((string) $existing->total_cost, (string) $intent->totalCost, 4) === 0;

                    if ($isMatch) {
                        return $existing;
                    }

                    throw new RuntimeException(
                        "Idempotency collision: same key with different intent. Key: {$intent->idempotencyKey}"
                    );
                }

                $costDeliveryDecision = $this->costDeliveryModePort->resolveForPosting(
                    $intent->propertyId,
                    $intent->itemId,
                    $intent->locationId,
                );

                if ($costDeliveryDecision->outcome === CostDeliveryPostingDecision::DEFERRED) {
                    throw new RuntimeException('CC_P01A_A4_DEFERRED_SOURCE_STAMP_PROHIBITED');
                }

                [$businessDate, $period] = $this->lockContext($intent->propertyId, $intent->businessDate, $intent->occurredAt);

                $stock = $this->stockRepo->createOrLockControlled($intent->propertyId, $intent->itemId, $intent->locationId);

                if ($businessDate->status !== PropertyBusinessDateStatusEnum::Open || ! $businessDate->is_open) {
                    throw new RuntimeException('Business date became closed during lock acquisition.');
                }

                if ($period->status !== FinancialPeriodStatusEnum::Open && $period->status !== FinancialPeriodStatusEnum::Reopened) {
                    throw new RuntimeException('Financial period became closed during lock acquisition.');
                }

                $quantityBefore = (string) $stock->physical_quantity;
                $quantityAfter = bcadd($quantityBefore, (string) $intent->quantityChange, 4);

                if (bccomp($quantityAfter, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        'stock' => ["Negative stock is not allowed for item {$intent->itemId} at location {$intent->locationId}"],
                    ]);
                }

                $property = Property::findOrFail($intent->propertyId);
                $currency = trim($property->currency);
                if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                    throw new RuntimeException("Property currency is invalid or missing. Actual currency: '{$currency}'");
                }

                $valuationScope = "property:{$intent->propertyId}:location:{$intent->locationId}:item:{$intent->itemId}";
                $valuationSequence = $this->sequenceRepo->allocateNext($intent->propertyId, $intent->locationId, $intent->itemId);

                $transaction = $this->transactionRepo->appendControlled(
                    $intent,
                    $quantityBefore,
                    $quantityAfter,
                    $valuationApprovalStatus,
                    $valuationApprovalReference,
                    $actorId,
                    $currency,
                    $period->id,
                    $valuationScope,
                    $valuationSequence,
                    $costDeliveryDecision,
                );

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

    private function resolveValuationAuthorizationEvidence(InventoryLedgerPostingIntent $intent): array
    {
        $type = $intent->transactionType;
        $docType = $intent->sourceDocumentType;
        $docId = $intent->sourceDocumentId;

        if ($type === TransactionTypeEnum::PurchaseReceipt) {
            if ($docType !== 'inventory_receipt' && $docType !== 'receiving_document') {
                throw new RuntimeException(
                    "Controlled posting source-document-type mismatch: expected 'inventory_receipt' or 'receiving_document', got '{$docType}'."
                );
            }
            $prefix = $docType === 'inventory_receipt' ? 'inventory_receipt' : 'receiving_document';

            return ['approved', "{$prefix}:{$docId}:posted"];
        }

        if ($type === TransactionTypeEnum::Issue) {
            if ($docType !== 'inventory_issue') {
                throw new RuntimeException(
                    "Controlled posting source-document-type mismatch: expected 'inventory_issue', got '{$docType}'."
                );
            }

            return ['approved', "inventory_issue:{$docId}:posted"];
        }

        if ($type === TransactionTypeEnum::AdjustmentIn || $type === TransactionTypeEnum::AdjustmentOut) {
            if ($docType !== 'inventory_adjustment') {
                throw new RuntimeException(
                    "Controlled posting source-document-type mismatch: expected 'inventory_adjustment', got '{$docType}'."
                );
            }

            return ['approved', "inventory_adjustment:{$docId}:approved"];
        }

        if ($type === TransactionTypeEnum::TransferOut || $type === TransactionTypeEnum::TransferIn) {
            if ($docType !== 'inventory_transfer') {
                throw new RuntimeException(
                    "Controlled posting source-document-type mismatch: expected 'inventory_transfer', got '{$docType}'."
                );
            }

            return ['approved', "inventory_transfer:{$docId}:completed"];
        }

        throw new RuntimeException(
            "Controlled posting rejected: unsupported transaction type '{$type->value}'."
        );
    }

    public function lockContext(string $propertyId, string $businessDate, Carbon $occurredAt): array
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

        if (! $dbDate || $dbDate->status !== PropertyBusinessDateStatusEnum::Open || ! $dbDate->is_open) {
            throw new RuntimeException('Business date is closed or missing.');
        }

        $year = $occurredAt->year;
        $month = $occurredAt->month;

        $period = FinancialPeriod::where('property_id', $propertyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->first();

        if (! $period || ($period->status !== FinancialPeriodStatusEnum::Open && $period->status !== FinancialPeriodStatusEnum::Reopened)) {
            throw new RuntimeException('Financial period is closed or missing.');
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
