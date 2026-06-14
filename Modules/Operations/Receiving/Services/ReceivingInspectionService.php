<?php

namespace Modules\Operations\Receiving\Services;

use Modules\Operations\Receiving\Repositories\ReceivingInspectionRepository;

class ReceivingInspectionService
{
    public function __construct(
        protected ReceivingInspectionRepository $inspectionRepository
    ) {}

    public function recordInspection(array $data): \Modules\Operations\Receiving\Models\ReceivingInspection
    {
        $data['inspected_at'] = now();
        $data['inspected_by'] = auth()->id() ?? null;
        return $this->inspectionRepository->create($data);
    }
}
