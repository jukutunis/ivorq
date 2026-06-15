<?php

namespace Modules\Finance\Banking\DTOs;

use InvalidArgumentException;

class MatchResultDTO
{
    public function __construct(
        public readonly bool $is_match,
        public readonly float $confidence_score,
        public readonly float $amount_score,
        public readonly float $date_score,
        public readonly float $reference_score,
        public readonly string $reason,
        public readonly ?MatchCandidateDTO $candidate
    ) {
        if ($this->confidence_score < 0 || $this->confidence_score > 100) {
            throw new InvalidArgumentException("Confidence score must be between 0 and 100.");
        }
    }
}
