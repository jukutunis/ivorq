<?php

namespace Modules\Finance\Banking\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;

class ReconciliationFinalizationService
{
    public function __construct(
        protected SessionStateGuard $stateGuard
    ) {}

    public function finalize(ReconciliationSession $session, string $userId, ?string $notes = null): void
    {
        // 1. Validation
        if ($session->status !== ReconciliationSessionStatusEnum::Completed) {
            throw new Exception("Finalization Exception: Only COMPLETED sessions can be finalized.");
        }

        // 2. Pre-Finalization Validation (Journals)
        $candidates = JournalCandidate::where('metadata->reconciliation_session_id', $session->id)
            ->get();

        foreach ($candidates as $candidate) {
            if (in_array($candidate->status, [
                JournalCandidateStatusEnum::CONFIGURATION_ERROR,
                JournalCandidateStatusEnum::POSTING_FAILED
            ])) {
                throw new Exception("Finalization Exception: Cannot finalize session with {$candidate->status->value} journals.");
            }
        }

        // 3. Duplicate Journal Audit (Idempotency)
        $duplicates = DB::table('journal_candidates')
            ->select('source_type', 'source_id', 'posting_event', DB::raw('COUNT(*) as total'))
            ->where('metadata->reconciliation_session_id', $session->id)
            ->groupBy('source_type', 'source_id', 'posting_event')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new Exception("Finalization Exception: Duplicate journal candidates detected.");
        }

        // 4. Governance Transition (will throw GovernanceException if rules violated)
        DB::transaction(function () use ($session, $userId, $notes) {
            $this->stateGuard->transitionTo($session, ReconciliationSessionStatusEnum::Finalized, $userId);

            if ($notes) {
                $session->update([
                    'finalization_notes' => $notes
                ]);
            }
        });
    }
}
