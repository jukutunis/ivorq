<?php

namespace Modules\Finance\FxReference\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Services\GeneralLedgerService;
use Modules\Foundation\User\Models\User;
use Throwable;

class RealizedFxAdjustmentPostingService
{
    public const PERMISSION = 'finance.journal-entry.post';

    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService
    ) {}

    /**
     * Post an authorized realized FX journal draft.
     */
    public function post(string $journalEntryId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId) {
            $actor = $this->resolveAuthorizedActor($actorId);

            $journal = JournalEntry::with('lines')
                ->where('id', $journalEntryId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRealizedFxDraft($journal);
            $this->assertActorCanAccessProperty($actor, $journal->property_id);

            if ($journal->status === JournalStatusEnum::Posted) {
                if ($journal->posted_by === $actor->id &&
                    $journal->posted_at !== null &&
                    $journal->posting_date !== null) {
                    return $this->loadOrderedLines($journal);
                }

                throw new \RuntimeException('Conflicting JournalEntry posting evidence already exists.');
            }

            $this->assertDraftReadyForPosting($journal);

            // Post using general ledger service
            $posted = $this->generalLedgerService->postJournalEntry($journal->id, $actor->id);

            // Update candidate status to POSTED
            if ($posted->journal_candidate_id) {
                DB::table('journal_candidates')
                    ->where('id', $posted->journal_candidate_id)
                    ->update([
                        'status' => JournalCandidateStatusEnum::POSTED->value,
                        'updated_at' => now(),
                    ]);
            }

            return $this->loadOrderedLines($posted);
        });
    }

    private function assertRealizedFxDraft(JournalEntry $journal): void
    {
        if ($journal->source_type !== 'ApSettlementAllocation' ||
            $journal->posting_event !== 'SupplierPaymentRealizedForeignExchange') {
            throw new \RuntimeException('Only realized FX drafts can be posted.');
        }
    }

    private function assertDraftReadyForPosting(JournalEntry $journal): void
    {
        if ($journal->status !== JournalStatusEnum::Draft) {
            throw new \RuntimeException('Only Draft JournalEntries can be posted.');
        }

        if ($journal->draft_finalization_authorized_by === null ||
            $journal->draft_finalization_authorized_at === null) {
            throw new \RuntimeException('JournalEntry draft must be finalization-authorized before posting.');
        }

        if ($journal->journal_candidate_id === null) {
            throw new \RuntimeException('Source JournalCandidate provenance is missing.');
        }

        $candidate = JournalCandidate::where('id', $journal->journal_candidate_id)
            ->first();

        if (!$candidate || $candidate->status !== JournalCandidateStatusEnum::APPROVED) {
            throw new \RuntimeException('Only approved JournalCandidate-derived drafts can be posted.');
        }
    }

    private function resolveAuthorizedActor(string $actorId): User
    {
        $actor = User::where('id', $actorId)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
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
            throw new AuthorizationException('Unauthorized to execute JournalEntry posting.');
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
