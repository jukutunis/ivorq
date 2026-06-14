<?php

namespace Modules\Finance\GeneralLedger\Repositories;

use Modules\Finance\GeneralLedger\Models\JournalCandidateLine;

class JournalCandidateLineRepository
{
    public function create(array $data): JournalCandidateLine
    {
        return JournalCandidateLine::create($data);
    }
}
