<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Repositories\JournalCandidateLineRepository;
use Modules\Finance\GeneralLedger\Repositories\JournalCandidateRepository;
use Modules\Finance\GeneralLedger\Exceptions\JournalCandidateBalanceException;

class JournalCandidateService
{
    public function __construct(
        private JournalCandidateRepository $candidateRepository,
        private JournalCandidateLineRepository $lineRepository
    ) {}

    public function create(array $data, array $lines = []): JournalCandidate
    {
        return DB::transaction(function () use ($data, $lines) {
            $data['status'] = JournalCandidateStatusEnum::DRAFT->value;
            $data['created_by'] = auth()->id();
            
            $candidate = $this->candidateRepository->create($data);

            foreach ($lines as $lineData) {
                $lineData['journal_candidate_id'] = $candidate->id;
                $this->lineRepository->create($lineData);
            }

            return $candidate->load('lines');
        });
    }

    public function submitForReview(string $id): JournalCandidate
    {
        $candidate = $this->candidateRepository->find($id);

        if ($candidate->status !== JournalCandidateStatusEnum::DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT candidates can be submitted for review.']
            ]);
        }

        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
        ]);
    }

    public function approve(string $id, ?string $userId = null): JournalCandidate
    {
        $candidate = $this->candidateRepository->find($id);

        if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW && $candidate->status !== JournalCandidateStatusEnum::DRAFT) {
             throw ValidationException::withMessages([
                'status' => ['Only DRAFT or PENDING_REVIEW candidates can be approved.']
            ]);
        }

        $this->validateBalance($candidate);

        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::APPROVED->value,
            'approved_at' => now(),
            'approved_by' => $userId ?? auth()->id(),
        ]);
    }

    public function reject(string $id, string $reason, ?string $userId = null): JournalCandidate
    {
        $candidate = $this->candidateRepository->find($id);

        if ($candidate->status === JournalCandidateStatusEnum::POSTED || $candidate->status === JournalCandidateStatusEnum::POSTED_LEGACY) {
            throw ValidationException::withMessages([
                'status' => ['POSTED or POSTED_LEGACY candidates cannot be rejected.']
            ]);
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is mandatory.']
            ]);
        }

        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::REJECTED->value,
            'rejected_by' => $userId ?? auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function markPosted(string $id): JournalCandidate
    {
        throw new \RuntimeException('Directly marking a candidate as POSTED is disabled. Use JournalCandidateFinalizationService.');
    }

    public function markPostingFailed(string $id, string $reason): JournalCandidate
    {
        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::POSTING_FAILED->value,
            'metadata' => array_merge($this->candidateRepository->find($id)->metadata ?? [], [
                'posting_error' => $reason
            ])
        ]);
    }

    private function validateBalance(JournalCandidate $candidate): void
    {
        $debits = $candidate->lines->where('entry_type', EntryTypeEnum::DEBIT)->sum('amount');
        $credits = $candidate->lines->where('entry_type', EntryTypeEnum::CREDIT)->sum('amount');

        // Allow a tiny float precision variance
        if (abs($debits - $credits) > 0.001) {
            throw new JournalCandidateBalanceException(
                "Candidate is unbalanced. Total Debits: {$debits}, Total Credits: {$credits}"
            );
        }
    }
}
