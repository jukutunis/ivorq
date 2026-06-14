<?php

namespace Modules\Finance\GeneralLedger\Repositories;

use Modules\Finance\GeneralLedger\Models\JournalCandidate;

class JournalCandidateRepository
{
    public function create(array $data): JournalCandidate
    {
        return JournalCandidate::create($data);
    }

    public function find(string $id): ?JournalCandidate
    {
        return JournalCandidate::findOrFail($id);
    }

    public function update(string $id, array $data): JournalCandidate
    {
        $candidate = $this->find($id);
        $candidate->update($data);
        return $candidate;
    }
}
