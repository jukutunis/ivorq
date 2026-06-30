<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Throwable;

class JournalEntryDraftFinalizationAuthorizationService
{
    public const PERMISSION = 'finance.journal-entry-draft.authorize-finalization';

    public function authorize(string $journalEntryId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId) {
            $journal = JournalEntry::with('lines')
                ->where('id', $journalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $actor = $this->resolveAuthorizedActor($actorId);

            $this->assertDraftIsAuthorizable($journal);
            $this->assertApprovedCandidateProvenance($journal);
            $this->assertBalancedLines($journal);

            if ($journal->draft_finalization_authorized_by !== null || $journal->draft_finalization_authorized_at !== null) {
                if (
                    $journal->draft_finalization_authorized_by === $actor->id &&
                    $journal->draft_finalization_authorized_at !== null
                ) {
                    return $this->loadOrderedLines($journal);
                }

                throw new RuntimeException('Conflicting JournalEntry draft finalization authorization evidence already exists.');
            }

            $journal->draft_finalization_authorized_by = $actor->id;
            $journal->draft_finalization_authorized_at = now();
            $journal->updated_by = $actor->id;
            $journal->save();

            return $this->loadOrderedLines($journal);
        });
    }

    private function resolveAuthorizedActor(string $actorId): User
    {
        $actor = User::where('id', $actorId)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Unauthorized to authorize JournalEntry draft finalization.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Unauthorized to authorize JournalEntry draft finalization.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Unauthorized to authorize JournalEntry draft finalization.');
        }

        return $actor;
    }

    private function assertDraftIsAuthorizable(JournalEntry $journal): void
    {
        if ($journal->status !== JournalStatusEnum::Draft) {
            throw new RuntimeException('Only Draft JournalEntries can be finalization-authorized.');
        }

        if ($journal->posting_date !== null) {
            throw new RuntimeException('Posted JournalEntries cannot be finalization-authorized as drafts.');
        }

        if ($journal->reversal_of_id !== null) {
            throw new RuntimeException('Reversal JournalEntries cannot be finalization-authorized by this draft workflow.');
        }
    }

    private function assertApprovedCandidateProvenance(JournalEntry $journal): void
    {
        if ($journal->journal_candidate_id === null) {
            throw new RuntimeException('JournalEntry draft finalization authorization requires source JournalCandidate provenance.');
        }

        $candidate = JournalCandidate::where('id', $journal->journal_candidate_id)
            ->lockForUpdate()
            ->first();

        if (!$candidate) {
            throw new RuntimeException('Source JournalCandidate provenance is missing.');
        }

        if (
            $candidate->status !== JournalCandidateStatusEnum::APPROVED ||
            $candidate->approved_by === null ||
            $candidate->approved_at === null
        ) {
            throw new RuntimeException('Only approved JournalCandidate-derived drafts can be finalization-authorized.');
        }

        if (
            $journal->property_id !== $candidate->property_id ||
            $journal->source_type !== $candidate->source_type ||
            $journal->source_id !== $candidate->source_id ||
            $journal->posting_event !== $candidate->posting_event
        ) {
            throw new RuntimeException('JournalEntry draft conflicts with source JournalCandidate provenance.');
        }
    }

    private function assertBalancedLines(JournalEntry $journal): void
    {
        if ($journal->lines->isEmpty()) {
            throw new RuntimeException('JournalEntry draft has no lines to authorize.');
        }

        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($journal->lines as $line) {
            if ($line->property_id !== $journal->property_id) {
                throw new RuntimeException('Cross-property JournalEntry lines cannot be finalization-authorized.');
            }

            if ($line->account_id === null || $line->account_id === '') {
                throw new RuntimeException('JournalEntry draft line account evidence is missing.');
            }

            $debit = (float) $line->debit_amount;
            $credit = (float) $line->credit_amount;

            if ($debit < 0 || $credit < 0) {
                throw new RuntimeException('JournalEntry draft line amounts must be non-negative.');
            }

            if (($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
                throw new RuntimeException('JournalEntry draft lines must carry exactly one active debit or credit side.');
            }

            $debitTotal += (int) round($debit * 100);
            $creditTotal += (int) round($credit * 100);
        }

        if ($debitTotal !== $creditTotal) {
            throw new RuntimeException('JournalEntry draft is out of balance and cannot be finalization-authorized.');
        }
    }

    private function loadOrderedLines(JournalEntry $journal): JournalEntry
    {
        $journal->setRelation(
            'lines',
            $journal->lines()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
        );

        return $journal;
    }
}
