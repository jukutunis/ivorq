<?php

namespace Modules\Finance\FxReference\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Throwable;

class RealizedFxAdjustmentFinalizationAuthorizationService
{
    public const PERMISSION = 'finance.journal-entry-draft.authorize-finalization';

    /**
     * Authorize finalization for a realized FX journal draft.
     */
    public function authorize(string $journalEntryId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId) {
            $actor = $this->resolveAuthorizedActor($actorId);

            $journal = JournalEntry::with('lines')
                ->where('id', $journalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRealizedFxDraft($journal);
            $this->assertActorCanAccessProperty($actor, $journal->property_id);
            $this->assertDraftIsAuthorizable($journal);
            $this->assertBalancedLines($journal);

            if ($journal->draft_finalization_authorized_by !== null || $journal->draft_finalization_authorized_at !== null) {
                if ($journal->draft_finalization_authorized_by === $actor->id &&
                    $journal->draft_finalization_authorized_at !== null) {
                    return $this->loadOrderedLines($journal);
                }

                throw new \RuntimeException('Conflicting JournalEntry draft finalization authorization evidence already exists.');
            }

            $journal->draft_finalization_authorized_by = $actor->id;
            $journal->draft_finalization_authorized_at = now();
            $journal->updated_by = $actor->id;
            $journal->save();

            return $this->loadOrderedLines($journal);
        });
    }

    private function assertRealizedFxDraft(JournalEntry $journal): void
    {
        if ($journal->source_type !== 'ApSettlementAllocation' ||
            $journal->posting_event !== 'SupplierPaymentRealizedForeignExchange') {
            throw new \RuntimeException('Only realized FX drafts can be finalization-authorized.');
        }
    }

    private function assertDraftIsAuthorizable(JournalEntry $journal): void
    {
        if ($journal->status !== JournalStatusEnum::Draft) {
            throw new \RuntimeException('Only Draft JournalEntries can be finalization-authorized.');
        }

        if ($journal->posting_date !== null) {
            throw new \RuntimeException('Posted JournalEntries cannot be finalization-authorized as drafts.');
        }

        if ($journal->journal_candidate_id === null) {
            throw new \RuntimeException('JournalEntry draft finalization authorization requires source JournalCandidate provenance.');
        }

        $candidate = JournalCandidate::where('id', $journal->journal_candidate_id)
            ->first();

        if (!$candidate || $candidate->status !== JournalCandidateStatusEnum::APPROVED) {
            throw new \RuntimeException('Source JournalCandidate is not approved.');
        }
    }

    private function assertBalancedLines(JournalEntry $journal): void
    {
        if ($journal->lines->count() !== 2) {
            throw new \RuntimeException('JournalEntry draft must have exactly 2 lines.');
        }

        $debitTotal = '0.00';
        $creditTotal = '0.00';

        foreach ($journal->lines as $line) {
            if ($line->property_id !== $journal->property_id) {
                throw new \RuntimeException('Cross-property JournalEntry lines are prohibited.');
            }

            if (!$line->account_id) {
                throw new \RuntimeException('JournalEntry draft line is missing account reference.');
            }

            $debit = (string) $line->debit_amount;
            $credit = (string) $line->credit_amount;

            $debitComp = bccomp($debit, '0.00', 2);
            $creditComp = bccomp($credit, '0.00', 2);

            if ($debitComp < 0 || $creditComp < 0) {
                throw new \RuntimeException('Line amounts must be non-negative.');
            }

            if (($debitComp > 0 && $creditComp > 0) || ($debitComp <= 0 && $creditComp <= 0)) {
                throw new \RuntimeException('Line must have exactly one debit or credit side.');
            }

            $debitTotal = bcadd($debitTotal, $debit, 2);
            $creditTotal = bcadd($creditTotal, $credit, 2);
        }

        if (bccomp($debitTotal, $creditTotal, 2) !== 0) {
            throw new \RuntimeException('JournalEntry draft is out of balance.');
        }
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

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Unauthorized to authorize JournalEntry draft finalization.');
        }
    }

    private function loadOrderedLines(JournalEntry $journal): JournalEntry
    {
        $journal->setRelation(
            'lines',
            $journal->lines()
                ->orderBy('id')
                ->get()
        );

        return $journal;
    }
}
