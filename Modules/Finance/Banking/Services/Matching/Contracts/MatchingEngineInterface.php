<?php

namespace Modules\Finance\Banking\Services\Matching\Contracts;

use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\DTOs\MatchCandidateDTO;
use Modules\Finance\Banking\DTOs\MatchResultDTO;

interface MatchingEngineInterface
{
    /**
     * Find potential match candidates for a given statement line.
     *
     * @param BankStatementLine $line
     * @return MatchCandidateDTO[]
     */
    public function findCandidates(BankStatementLine $line): array;

    /**
     * Score a single candidate against the line.
     */
    public function scoreCandidate(BankStatementLine $line, MatchCandidateDTO $candidate): MatchCandidateDTO;

    /**
     * Evaluate candidates and return the final match result.
     *
     * @param BankStatementLine $line
     * @param MatchCandidateDTO[] $candidates
     * @return MatchResultDTO
     */
    public function evaluate(BankStatementLine $line, array $candidates): MatchResultDTO;
}
