<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\Finance\CostControl\Models\CostLedgerEntry;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;

class CostIssuePostingEngine
{
    public function __construct(
        private readonly OperationalIdentityMappingService $mappingService,
        private readonly OperationalIdentityValidationService $validationService
    ) {}

    public function process(CostLedgerEntry $entry): JournalCandidate
    {
        if ($entry->entry_type !== 'issue') {
            throw new Exception("CostIssuePostingEngine only processes 'issue' CostLedgerEntry entries.");
        }

        $propertyId = $entry->property_id;
        $date = Carbon::parse($entry->business_date);
        $totalCost = abs((float) $entry->value_delta);

        // Idempotency: Create Candidate using the CostLedgerEntry as unique source
        try {
            $candidate = JournalCandidate::firstOrCreate([
                'property_id' => $propertyId,
                'source_type' => 'CostLedgerEntry',
                'source_id' => $entry->id,
                'posting_event' => 'InventoryIssueCost',
            ], [
                'status' => JournalCandidateStatusEnum::DRAFT->value,
                'candidate_date' => $date->toDateString(),
                'description' => "Enrolled Inventory Issue Cost Posting for Entry {$entry->id}",
                'metadata' => [
                    'cost_ledger_entry_id' => $entry->id,
                    'source_inventory_transaction_id' => $entry->source_inventory_transaction_id,
                    'quantity_delta' => $entry->quantity_delta,
                    'unit_cost' => $entry->unit_cost,
                    'value_delta' => $entry->value_delta,
                ],
                'created_by' => auth()->id(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $candidate = JournalCandidate::where([
                'property_id' => $propertyId,
                'source_type' => 'CostLedgerEntry',
                'source_id' => $entry->id,
                'posting_event' => 'InventoryIssueCost',
            ])->firstOrFail();
        }

        if (!in_array($candidate->status, [JournalCandidateStatusEnum::DRAFT, JournalCandidateStatusEnum::CONFIGURATION_ERROR])) {
            return $candidate;
        }

        try {
            DB::transaction(function () use ($candidate, $propertyId, $date, $totalCost) {
                // Delete existing lines if this is a retry/re-evaluation
                $candidate->lines()->delete();

                $debitIdentity = OperationalIdentityEnum::COST_OF_CONSUMPTION;
                $creditIdentity = OperationalIdentityEnum::INVENTORY;

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

                // Update Status to PENDING_REVIEW (Standard draft/review status)
                $candidate->update([
                    'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                    'metadata' => array_merge($candidate->metadata ?? [], ['mapping_error' => null])
                ]);
            });

        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException $e) {
            $this->logConfigurationError($candidate, $e);
            throw $e; // Re-throw to propagate failure atomically and fail-closed!
        } catch (Exception $e) {
            $this->logConfigurationError($candidate, $e);
            throw $e; // Re-throw to propagate failure atomically and fail-closed!
        }

        return $candidate;
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
