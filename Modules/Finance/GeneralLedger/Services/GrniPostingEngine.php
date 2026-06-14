<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;
use Modules\Operations\Inventory\Events\InventoryReceiptPosted;

class GrniPostingEngine
{
    public function __construct(
        private OperationalIdentityMappingService $mappingService,
        private OperationalIdentityValidationService $validationService
    ) {}

    public function handle(InventoryReceiptPosted $event): void
    {
        $this->process($event->receipt);
    }

    public function process(InventoryReceipt $receipt): void
    {
        $propertyId = $receipt->property_id;
        $date = Carbon::parse($receipt->posted_at ?? $receipt->created_at);
        
        // Calculate total cost received
        $totalCost = $receipt->lines->sum(function ($line) {
            return (float) $line->quantity * (float) $line->unit_cost;
        });

        // Idempotency: Create Candidate
        $candidate = JournalCandidate::firstOrCreate([
            'source_type' => 'InventoryReceipt',
            'source_id' => $receipt->id,
            'posting_event' => 'InventoryReceiptAccrual',
        ], [
            'property_id' => $propertyId,
            'status' => JournalCandidateStatusEnum::DRAFT->value,
            'candidate_date' => $date->toDateString(),
            'description' => "GRNI Accrual for Receipt {$receipt->receipt_number}",
            'metadata' => [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'supplier_name' => $receipt->supplier_name,
                'total_cost' => $totalCost,
            ],
            'created_by' => $receipt->posted_by,
        ]);

        if (!in_array($candidate->status, [JournalCandidateStatusEnum::DRAFT, JournalCandidateStatusEnum::CONFIGURATION_ERROR])) {
            return;
        }

        try {
            DB::transaction(function () use ($candidate, $propertyId, $date, $totalCost) {
                // Support Re-Evaluation by flushing existing lines
                $candidate->lines()->delete();

                // Accrual Identities:
                // Debit INVENTORY
                // Credit GRNI_RECEIPT
                
                $debitIdentity = OperationalIdentityEnum::INVENTORY;
                $creditIdentity = OperationalIdentityEnum::GRNI_RECEIPT;

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

                // Update Status to PENDING_REVIEW
                $candidate->update([
                    'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                    'metadata' => array_merge($candidate->metadata ?? [], ['mapping_error' => null])
                ]);
            });

        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException $e) {
            $this->logConfigurationError($candidate, $e);
        } catch (Exception $e) {
            $this->logConfigurationError($candidate, $e);
        }
    }

    private function logConfigurationError(JournalCandidate $candidate, Exception $e): void
    {
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
