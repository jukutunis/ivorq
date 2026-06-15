<?php

namespace Modules\Finance\Banking\DTOs;

use InvalidArgumentException;

class MatchCandidateDTO
{
    public function __construct(
        public readonly string $matchable_type,
        public readonly string $matchable_id,
        public readonly string $bank_statement_line_id,
        public float $amount_score = 0,
        public float $date_score = 0,
        public float $reference_score = 0,
        public float $total_score = 0
    ) {
        if (empty($this->matchable_type) || empty($this->matchable_id) || empty($this->bank_statement_line_id)) {
            throw new InvalidArgumentException("Identifiers cannot be null or empty.");
        }
    }

    public function setScores(float $amount, float $date, float $reference, float $total): void
    {
        if ($amount < 0 || $date < 0 || $reference < 0 || $total < 0) {
            throw new InvalidArgumentException("Scores cannot be negative.");
        }
        if ($amount > 100 || $date > 100 || $reference > 100 || $total > 100) {
            throw new InvalidArgumentException("Scores cannot exceed 100.");
        }

        $this->amount_score = $amount;
        $this->date_score = $date;
        $this->reference_score = $reference;
        $this->total_score = $total;
    }
}
