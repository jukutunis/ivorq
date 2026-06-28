<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\JournalEntryLine;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Exception;

class JournalReversalService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
    ) {}

    public function reverse(
        string $originalJournalEntryId,
        string $reversalTransactionDate,
        string $reason,
        string $actorId
    ): JournalEntry {
        if (trim($actorId) === '') {
            throw new Exception('actorId is required.');
        }
        if (trim($reason) === '') {
            throw new Exception('reason is required.');
        }
        if (trim($reversalTransactionDate) === '') {
            throw new Exception('reversalTransactionDate is required.');
        }

        return DB::transaction(function () use (
            $originalJournalEntryId,
            $reversalTransactionDate,
            $reason,
            $actorId
        ) {
            // Step 1: Lock the original.
            $original = JournalEntry::with('lines')
                ->lockForUpdate()
                ->findOrFail($originalJournalEntryId);

            // Step 2: Validate the original.
            if ($original->status !== JournalStatusEnum::Posted) {
                throw new Exception(
                    "Cannot reverse journal {$originalJournalEntryId}: status must be Posted (actual={$original->status->value})."
                );
            }

            if ($original->reversal_of_id !== null) {
                throw new Exception(
                    "Cannot reverse journal {$originalJournalEntryId}: it is itself a reversal."
                );
            }

            // Step 3: Idempotent path — return existing Posted reversal if one already exists.
            $existing = JournalEntry::where('reversal_of_id', $originalJournalEntryId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === JournalStatusEnum::Posted) {
                    return $existing->load('lines');
                }
                throw new Exception(
                    "A non-Posted reversal already exists for journal {$originalJournalEntryId} (id={$existing->id})."
                );
            }

            // Step 4: Original lines already eager-loaded.
            $originalLines = $original->lines;

            if ($originalLines->isEmpty()) {
                throw new Exception(
                    "Cannot reverse journal {$originalJournalEntryId}: original has no lines."
                );
            }

            // Step 5: Create Draft reversal header.
            $reversal = JournalEntry::create([
                'property_id'          => $original->property_id,
                'transaction_date'     => $reversalTransactionDate,
                'status'               => JournalStatusEnum::Draft,
                'source_module'        => $original->source_module,
                'source_type'          => $original->source_type,
                'source_id'            => $original->source_id,
                'reversal_of_id'       => $original->id,
                'journal_candidate_id' => null,
                'posting_event'        => 'JournalReversal',
                'description'          => "Reversal of journal {$original->id}. Reason: " . trim($reason),
                'created_by'           => $actorId,
            ]);

            // Step 6: Create reversal lines with debit and credit swapped.
            // No arithmetic — values are swapped directly to avoid float imprecision.
            foreach ($originalLines as $line) {
                JournalEntryLine::create([
                    'property_id'      => $line->property_id,
                    'journal_entry_id' => $reversal->id,
                    'account_id'       => $line->account_id,
                    'department_id'    => $line->department_id,
                    'debit_amount'     => $line->credit_amount,
                    'credit_amount'    => $line->debit_amount,
                    'memo'             => $line->memo,
                ]);
            }

            // Step 7: Post the reversal through the canonical final-posting authority.
            $posted = $this->generalLedgerService->postJournalEntry($reversal->id);

            // Step 8: Return the Posted reversal.
            return $posted->load('lines');
        });
    }
}
