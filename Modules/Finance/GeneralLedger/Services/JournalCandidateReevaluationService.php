<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Validation\ValidationException;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Operations\Inventory\Models\InventoryTransaction;

class JournalCandidateReevaluationService
{
    public function __construct(
        private VariancePostingEngine $varianceEngine
    ) {}

    public function reevaluate(string $candidateId, ?string $userId = null): JournalCandidate
    {
        $candidate = JournalCandidate::findOrFail($candidateId);

        if ($candidate->status !== JournalCandidateStatusEnum::CONFIGURATION_ERROR) {
            throw ValidationException::withMessages([
                'status' => ['Only CONFIGURATION_ERROR candidates can be re-evaluated.']
            ]);
        }

        // Increment count first
        $candidate->increment('reevaluation_count');
        
        $candidate->update([
            'reevaluated_by' => $userId ?? auth()->id(),
            'reevaluated_at' => now(),
        ]);

        if ($candidate->source_type === 'InventoryTransaction' && $candidate->posting_event === 'InventoryAdjustmentVariance') {
            $transaction = InventoryTransaction::findOrFail($candidate->source_id);
            
            // The engine already contains logic to clear lines and trap configuration errors
            $this->varianceEngine->process($transaction);

            // Refresh to see if it recovered or remained in error
            $candidate->refresh();

            if ($candidate->status === JournalCandidateStatusEnum::CONFIGURATION_ERROR) {
                $error = $candidate->metadata['mapping_error'] ?? null;
                $candidate->update([
                    'last_reevaluation_error' => $error ? json_encode($error) : 'Unknown error'
                ]);
            } else {
                $candidate->update([
                    'last_reevaluation_error' => null
                ]);
            }
        } else {
            throw new Exception("Unsupported source type or posting event for re-evaluation: {$candidate->source_type} / {$candidate->posting_event}");
        }

        return $candidate->refresh();
    }
}
