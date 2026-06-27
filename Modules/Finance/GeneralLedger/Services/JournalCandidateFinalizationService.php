<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;

class JournalCandidateFinalizationService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly OperationalIdentityMappingService $mappingService
    ) {}

    public function finalize(string $candidateId): JournalEntry
    {
        return DB::transaction(function () use ($candidateId) {
            // 1. Lock the JournalCandidate row
            $candidate = JournalCandidate::where('id', $candidateId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Resolve terminal states
            if ($candidate->status === JournalCandidateStatusEnum::POSTED) {
                $entry = JournalEntry::where('journal_candidate_id', $candidate->id)->first();
                if (!$entry || $entry->status !== JournalStatusEnum::Posted) {
                    throw new Exception("Candidate status is POSTED but matching Posted JournalEntry is missing or inconsistent.");
                }
                return $entry;
            }

            if ($candidate->status === JournalCandidateStatusEnum::POSTED_LEGACY) {
                throw new Exception("Candidate has terminal POSTED_LEGACY status and cannot be finalized.");
            }

            if ($candidate->status !== JournalCandidateStatusEnum::APPROVED) {
                throw new Exception("Only APPROVED candidates can be finalized. Current status: '{$candidate->status->value}'.");
            }

            // 3. Load and validate candidate lines
            $lines = $candidate->lines;
            if ($lines->isEmpty()) {
                throw new Exception("Candidate has no lines and cannot be finalized.");
            }

            // 4. Validate exact final-line representability and debit/credit balance
            $debitTotal = 0;
            $creditTotal = 0;

            foreach ($lines as $line) {
                $amount = (float) $line->amount;
                if ($amount <= 0) {
                    throw new Exception("Candidate line amount must be positive. Got {$amount}.");
                }

                $amountStr = number_format($amount, 4, '.', '');
                $rounded = round($amount, 2);
                if ($rounded <= 0) {
                    throw new Exception("Rounded candidate line amount must be positive. Got {$rounded}.");
                }

                $roundedStr = number_format($rounded, 4, '.', '');
                if ($amountStr !== $roundedStr) {
                    throw new Exception("Candidate line amount {$amount} is not representable as a 2-decimal ledger amount.");
                }

                if ($line->entry_type === EntryTypeEnum::DEBIT) {
                    $debitTotal += (int) round($rounded * 100);
                } elseif ($line->entry_type === EntryTypeEnum::CREDIT) {
                    $creditTotal += (int) round($rounded * 100);
                } else {
                    throw new Exception("Invalid entry type: '{$line->entry_type->value}'.");
                }
            }

            if ($debitTotal !== $creditTotal) {
                throw new Exception("Candidate is out of balance. Debits: " . ($debitTotal / 100) . ", Credits: " . ($creditTotal / 100));
            }

            // 5. Resolve source_module only from approved source types
            if (!in_array($candidate->source_type, ['InventoryTransaction', 'InventoryReceipt'])) {
                throw new Exception("Unsupported candidate source_type '{$candidate->source_type}' for finalization.");
            }

            if (strlen($candidate->source_id) > 26) {
                throw new Exception("Candidate source_id is too long for the final JournalEntry column.");
            }

            $sourceModule = 'Inventory';

            // 6. Create Draft JournalEntry
            $journal = JournalEntry::create([
                'property_id' => $candidate->property_id,
                'transaction_date' => $candidate->candidate_date->toDateString(),
                'description' => $candidate->description,
                'status' => JournalStatusEnum::Draft,
                'source_module' => $sourceModule,
                'source_type' => $candidate->source_type,
                'source_id' => $candidate->source_id,
                'journal_candidate_id' => $candidate->id,
                'posting_event' => $candidate->posting_event,
            ]);

            // 7. Create JournalEntryLines from candidate lines
            foreach ($lines as $line) {
                $mapping = $this->mappingService->resolve(
                    $candidate->property_id,
                    $line->operational_identity,
                    $candidate->candidate_date
                );

                $roundedAmount = round((float) $line->amount, 2);

                $journal->lines()->create([
                    'property_id' => $candidate->property_id,
                    'account_id' => $mapping->account_id,
                    'department_id' => $line->cost_center_id,
                    'debit_amount' => $line->entry_type === EntryTypeEnum::DEBIT ? $roundedAmount : 0,
                    'credit_amount' => $line->entry_type === EntryTypeEnum::CREDIT ? $roundedAmount : 0,
                    'memo' => $line->notes,
                ]);
            }

            // 8. Invoke existing GeneralLedgerService::postJournalEntry()
            $postedJournal = $this->glService->postJournalEntry($journal->id);

            // 9. Update candidate status to POSTED
            $candidate->update([
                'status' => JournalCandidateStatusEnum::POSTED->value,
            ]);

            return $postedJournal;
        });
    }
}
