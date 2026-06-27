<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;

class VariancePostingEngine
{
    public function __construct(
        private OperationalIdentityMappingService $mappingService,
        private OperationalIdentityValidationService $validationService
    ) {}

    public function process(InventoryTransaction $transaction): void
    {
        // Double-check the transaction type
        if (!in_array($transaction->transaction_type, [TransactionTypeEnum::AdjustmentIn, TransactionTypeEnum::AdjustmentOut])) {
            return;
        }

        $propertyId = $transaction->property_id;
        $date = Carbon::parse($transaction->posted_at ?? $transaction->created_at);
        $totalCost = abs((float) $transaction->total_cost);

        // Idempotency
        try {
            $candidate = JournalCandidate::firstOrCreate([
                'property_id' => $propertyId,
                'source_type' => 'InventoryTransaction',
                'source_id' => $transaction->id,
                'posting_event' => 'InventoryAdjustmentVariance',
            ], [
                'status' => JournalCandidateStatusEnum::DRAFT->value,
                'candidate_date' => $date->toDateString(),
                'description' => "Stock Opname Variance " . ($transaction->transaction_type === TransactionTypeEnum::AdjustmentIn ? 'Positive' : 'Negative') . " Adjustment for Item {$transaction->item_id}",
                'metadata' => [
                    'adjustment_id' => $transaction->reference_id,
                    'item_id' => $transaction->item_id,
                    'location_id' => $transaction->location_id,
                    'quantity_variance' => $transaction->quantity_change,
                    'unit_cost' => $transaction->unit_cost,
                    'total_cost' => $totalCost,
                ],
                'created_by' => $transaction->posted_by,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $candidate = JournalCandidate::where([
                'property_id' => $propertyId,
                'source_type' => 'InventoryTransaction',
                'source_id' => $transaction->id,
                'posting_event' => 'InventoryAdjustmentVariance',
            ])->firstOrFail();
        }

        // If the candidate was already successfully processed (e.g., PENDING_REVIEW, APPROVED, POSTED), skip it.
        // If it's DRAFT or CONFIGURATION_ERROR, we will try to process lines.
        if (!in_array($candidate->status, [JournalCandidateStatusEnum::DRAFT, JournalCandidateStatusEnum::CONFIGURATION_ERROR])) {
            return;
        }

        try {
            DB::transaction(function () use ($candidate, $propertyId, $date, $transaction, $totalCost) {
                // Delete existing lines if this is a retry
                $candidate->lines()->delete();

                $inventoryIdentity = OperationalIdentityEnum::INVENTORY;
                
                if ($transaction->transaction_type === TransactionTypeEnum::AdjustmentIn) {
                    // Positive Variance
                    $varianceIdentity = OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN;
                    $debitIdentity = $inventoryIdentity;
                    $creditIdentity = $varianceIdentity;
                } else {
                    // Negative Variance
                    $varianceIdentity = OperationalIdentityEnum::INVENTORY_ADJUSTMENT_LOSS;
                    $debitIdentity = $varianceIdentity;
                    $creditIdentity = $inventoryIdentity;
                }

                // Resolve and Validate Debit
                $debitMapping = $this->mappingService->resolve($propertyId, $debitIdentity, $date);
                $this->validationService->validate($debitIdentity, $debitMapping->account);

                // Resolve and Validate Credit
                $creditMapping = $this->mappingService->resolve($propertyId, $creditIdentity, $date);
                $this->validationService->validate($creditIdentity, $creditMapping->account);

                // Create Lines
                $candidate->lines()->create([
                    'operational_identity' => $debitIdentity->value,
                    'entry_type' => EntryTypeEnum::DEBIT->value,
                    'amount' => $totalCost,
                    'cost_center_id' => $debitMapping->cost_center_id,
                ]);

                $candidate->lines()->create([
                    'operational_identity' => $creditIdentity->value,
                    'entry_type' => EntryTypeEnum::CREDIT->value,
                    'amount' => $totalCost,
                    'cost_center_id' => $creditMapping->cost_center_id,
                ]);

                // Update Status
                $candidate->update([
                    'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                    'metadata' => array_merge($candidate->metadata ?? [], ['mapping_error' => null])
                ]);
            });

        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException $e) {
            $metadata = $candidate->metadata ?? [];
            $metadata['mapping_error'] = [
                'type' => class_basename($e),
                'message' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];

            $candidate->update([
                'status' => JournalCandidateStatusEnum::CONFIGURATION_ERROR->value,
                'metadata' => $metadata,
            ]);
            
            // Do not re-throw, Inventory remains source of truth, operational flow succeeds
        } catch (Exception $e) {
            $metadata = $candidate->metadata ?? [];
            $metadata['mapping_error'] = [
                'type' => class_basename($e),
                'message' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];

            $candidate->update([
                'status' => JournalCandidateStatusEnum::CONFIGURATION_ERROR->value,
                'metadata' => $metadata,
            ]);
        }
    }
}
