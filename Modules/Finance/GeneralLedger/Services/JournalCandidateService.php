<?php

namespace Modules\Finance\GeneralLedger\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Repositories\JournalCandidateLineRepository;
use Modules\Finance\GeneralLedger\Repositories\JournalCandidateRepository;

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

        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::APPROVED->value,
            'approved_at' => now(),
            'approved_by' => $userId ?? auth()->id(),
        ]);
    }

    public function reject(string $id): JournalCandidate
    {
        $candidate = $this->candidateRepository->find($id);

        if ($candidate->status === JournalCandidateStatusEnum::POSTED) {
            throw ValidationException::withMessages([
                'status' => ['POSTED candidates cannot be rejected.']
            ]);
        }

        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::REJECTED->value,
        ]);
    }

    public function markPosted(string $id): JournalCandidate
    {
        $candidate = $this->candidateRepository->find($id);

        if ($candidate->status !== JournalCandidateStatusEnum::APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only APPROVED candidates can be marked as POSTED.']
            ]);
        }

        return $this->candidateRepository->update($id, [
            'status' => JournalCandidateStatusEnum::POSTED->value,
        ]);
    }
}
