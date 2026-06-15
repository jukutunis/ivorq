<?php

namespace Modules\Finance\Banking\DTOs;

use InvalidArgumentException;

class MatchingConfiguration
{
    public function __construct(
        public readonly int $date_tolerance_days,
        public readonly float $amount_tolerance,
        public readonly float $reference_similarity_percent,
        public readonly float $auto_match_threshold
    ) {
        if ($this->date_tolerance_days < 0 || $this->amount_tolerance < 0) {
            throw new InvalidArgumentException("Tolerances cannot be negative.");
        }
        if ($this->reference_similarity_percent < 0 || $this->reference_similarity_percent > 100) {
            throw new InvalidArgumentException("Reference similarity percent must be between 0 and 100.");
        }
        if ($this->auto_match_threshold < 0 || $this->auto_match_threshold > 100) {
            throw new InvalidArgumentException("Auto match threshold must be between 0 and 100.");
        }
    }
}
