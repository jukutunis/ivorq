<?php

namespace Modules\Finance\Banking\Services\Matching;

use Carbon\Carbon;
use Modules\Finance\Banking\Services\Matching\Contracts\MatchingEngineInterface;
use Modules\Finance\Banking\DTOs\MatchingConfiguration;

abstract class AbstractMatchingEngine implements MatchingEngineInterface
{
    protected MatchingConfiguration $config;

    public function __construct(MatchingConfiguration $config)
    {
        $this->config = $config;
    }

    protected function calculateAmountScore(float $bankAmount, float $treasuryAmount): float
    {
        $diff = abs(abs($bankAmount) - abs($treasuryAmount));
        
        if ($diff == 0.0) {
            return 100.0;
        }

        if ($diff <= $this->config->amount_tolerance) {
            // Partial score based on how close it is to the tolerance
            $ratio = 1 - ($diff / $this->config->amount_tolerance);
            return max(0, $ratio * 100);
        }

        return 0.0;
    }

    protected function calculateDateScore(Carbon $bankDate, Carbon $treasuryDate): float
    {
        $diffDays = abs($bankDate->diffInDays($treasuryDate, false));

        if ($diffDays == 0) {
            return 100.0;
        }

        if ($diffDays <= $this->config->date_tolerance_days) {
            $ratio = 1 - ($diffDays / $this->config->date_tolerance_days);
            return max(0, $ratio * 100);
        }

        return 0.0;
    }

    protected function calculateReferenceScore(?string $bankReference, ?string $treasuryReference): float
    {
        if (empty($bankReference) || empty($treasuryReference)) {
            return 0.0;
        }

        $bank = strtolower(trim($bankReference));
        $treasury = strtolower(trim($treasuryReference));

        if ($bank === $treasury) {
            return 100.0;
        }

        // Simple Levenshtein ratio
        $levenshtein = levenshtein($bank, $treasury);
        $maxLength = max(strlen($bank), strlen($treasury));
        
        if ($maxLength === 0) return 0.0;
        
        $similarity = (1 - ($levenshtein / $maxLength)) * 100;

        if ($similarity >= $this->config->reference_similarity_percent) {
            return $similarity;
        }

        return 0.0;
    }

    protected function calculateConfidence(float $amountScore, float $dateScore, float $referenceScore): float
    {
        // Must meet tolerance constraints to score anything above 0.
        // If amount score is 0, it's not a match.
        if ($amountScore == 0) {
            return 0.0;
        }

        if ($amountScore == 100 && $dateScore == 100 && $referenceScore == 100) {
            return 100.0; // Exact match
        }

        if ($amountScore == 100 && $dateScore < 100 && $referenceScore == 100 && $dateScore > 0) {
            return 95.0; // Date variance only
        }

        if ($amountScore == 100 && $dateScore == 100 && $referenceScore < 100 && $referenceScore >= $this->config->reference_similarity_percent) {
            return 90.0; // Reference variance only
        }

        if ($amountScore > 0 && $dateScore > 0) {
            return 80.0; // Tolerance match
        }

        // Below threshold
        return 50.0; // Manual review required
    }
}
