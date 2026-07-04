<?php

namespace Modules\Finance\FxReference\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\JournalEntryLine;
use Modules\Finance\GeneralLedger\Services\OperationalIdentityMappingService;
use Modules\Foundation\User\Models\User;
use Throwable;

class RealizedFxAdjustmentDraftMaterializationService
{
    public const PERMISSION = 'finance.journal-candidate.materialize-draft';

    public function __construct(
        private readonly OperationalIdentityMappingService $mappingService
    ) {}

    /**
     * Materialize an approved realized FX candidate into a draft JournalEntry.
     */
    public function materialize(string $candidateId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($candidateId, $actorId) {
            $actor = $this->resolveAuthorizedActor($actorId);

            $candidate = JournalCandidate::where('id', $candidateId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRealizedFxCandidate($candidate);
            $this->assertActorCanAccessProperty($actor, $candidate->property_id);

            if ($candidate->status !== JournalCandidateStatusEnum::APPROVED) {
                throw new \RuntimeException('Only APPROVED realized FX candidates can be materialized.');
            }

            $candidateLines = $candidate->lines()
                ->orderBy('id')
                ->get();

            if ($candidateLines->count() !== 2) {
                throw new \RuntimeException('Realized FX candidate must have exactly 2 lines.');
            }

            // Look up existing draft
            $existing = JournalEntry::where('journal_candidate_id', $candidate->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status !== JournalStatusEnum::Draft) {
                    throw new \RuntimeException('Candidate draft has already been finalized or posted.');
                }
                if ($existing->created_by !== $actor->id) {
                    throw new \RuntimeException('Idempotent retry has different creator actor.');
                }
                // Verify identical replay matches
                $existingLines = $existing->lines()->orderBy('id')->get();
                if ($existingLines->count() !== 2) {
                    throw new \RuntimeException('Existing draft lines conflict with candidate.');
                }

                foreach ($candidateLines as $idx => $cLine) {
                    $mapping = $this->mappingService->resolve(
                        $candidate->property_id,
                        $cLine->operational_identity,
                        $candidate->candidate_date
                    );
                    $eLine = $existingLines[$idx];

                    if ($eLine->account_id !== $mapping->account_id) {
                        throw new \RuntimeException('Existing draft lines conflict with candidate.');
                    }

                    if ($cLine->entry_type === EntryTypeEnum::DEBIT) {
                        if (bccomp((string)$eLine->debit_amount, (string)$cLine->amount, 2) !== 0 ||
                            bccomp((string)$eLine->credit_amount, '0.00', 2) !== 0) {
                            throw new \RuntimeException('Existing draft lines conflict with candidate.');
                        }
                    } else {
                        if (bccomp((string)$eLine->credit_amount, (string)$cLine->amount, 2) !== 0 ||
                            bccomp((string)$eLine->debit_amount, '0.00', 2) !== 0) {
                            throw new \RuntimeException('Existing draft lines conflict with candidate.');
                        }
                    }
                }

                return $this->loadOrderedLines($existing);
            }

            // Create JournalEntry draft
            $journal = new JournalEntry([
                'property_id' => $candidate->property_id,
                'transaction_date' => $candidate->candidate_date->toDateString(),
                'description' => $candidate->description,
                'status' => JournalStatusEnum::Draft,
                'source_module' => 'GeneralLedger',
                'source_type' => $candidate->source_type,
                'source_id' => $candidate->source_id,
                'journal_candidate_id' => $candidate->id,
                'posting_event' => $candidate->posting_event,
            ]);

            $journal->created_by = $actor->id;
            $journal->updated_by = $actor->id;
            $journal->save();

            // Create lines
            foreach ($candidateLines as $cLine) {
                $mapping = $this->mappingService->resolve(
                    $candidate->property_id,
                    $cLine->operational_identity,
                    $candidate->candidate_date
                );

                $debitAmount = $cLine->entry_type === EntryTypeEnum::DEBIT ? $cLine->amount : '0.00';
                $creditAmount = $cLine->entry_type === EntryTypeEnum::CREDIT ? $cLine->amount : '0.00';

                $line = new JournalEntryLine([
                    'property_id' => $candidate->property_id,
                    'account_id' => $mapping->account_id,
                    'department_id' => $cLine->cost_center_id,
                    'debit_amount' => $debitAmount,
                    'credit_amount' => $creditAmount,
                    'memo' => $cLine->notes,
                ]);

                $line->created_by = $actor->id;
                $line->updated_by = $actor->id;

                $journal->lines()->save($line);
            }

            return $this->loadOrderedLines($journal);
        });
    }

    private function assertRealizedFxCandidate(JournalCandidate $candidate): void
    {
        if ($candidate->source_type !== 'ApSettlementAllocation' ||
            $candidate->posting_event !== 'SupplierPaymentRealizedForeignExchange') {
            throw new \RuntimeException('Only realized FX candidates can be materialized.');
        }
    }

    private function resolveAuthorizedActor(string $actorId): User
    {
        $actor = User::where('id', $actorId)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
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
            throw new AuthorizationException('Unauthorized to materialize journal candidate drafts.');
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
