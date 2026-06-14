<?php

namespace Modules\Operations\Receiving\Services;

use Modules\Operations\Receiving\Repositories\ReceivingDiscrepancyRepository;

class ReceivingDiscrepancyService
{
    public function __construct(
        protected ReceivingDiscrepancyRepository $discrepancyRepository
    ) {}

    public function reportDiscrepancy(array $data): \Modules\Operations\Receiving\Models\ReceivingDiscrepancy
    {
        $data['status'] = 'pending';
        return $this->discrepancyRepository->create($data);
    }

    public function resolveDiscrepancy(string $discrepancyId, array $resolutionData): void
    {
        $discrepancy = $this->discrepancyRepository->findById($discrepancyId);
        
        $discrepancy->update([
            'status' => 'resolved',
            'resolved_by' => auth()->id() ?? null,
            'resolved_at' => now(),
            'resolution_notes' => $resolutionData['resolution_notes'] ?? null,
        ]);
    }
}
