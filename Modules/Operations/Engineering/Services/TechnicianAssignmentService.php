<?php

namespace Modules\Operations\Engineering\Services;

use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Modules\Operations\Engineering\Repositories\TechnicianAssignmentRepository;

class TechnicianAssignmentService
{
    public function __construct(
        private TechnicianAssignmentRepository $repository,
    ) {}

    public function create(array $data): TechnicianAssignment
    {
        return $this->repository->create($data);
    }

    public function update(string $id, array $data): TechnicianAssignment
    {
        return $this->repository->update($id, $data);
    }

    /**
     * Mark an assignment as completed and record the completion timestamp.
     *
     * Optionally accepts hours_worked to log actual time spent.
     */
    public function complete(string $id, array $data = []): TechnicianAssignment
    {
        $updates = [
            'status'       => TechnicianAssignmentStatusEnum::Completed->value,
            'completed_at' => now(),
        ];

        if (isset($data['hours_worked'])) {
            $updates['hours_worked'] = $data['hours_worked'];
        }

        return $this->repository->update($id, $updates);
    }

    /**
     * Relieve a technician from the assignment — replaced by another.
     * The assignment record is kept as a historical audit trail.
     */
    public function relieve(string $id): TechnicianAssignment
    {
        return $this->repository->update($id, [
            'status' => TechnicianAssignmentStatusEnum::Relieved->value,
        ]);
    }

    /**
     * Cancel an assignment before any work was started.
     */
    public function cancel(string $id): TechnicianAssignment
    {
        return $this->repository->update($id, [
            'status' => TechnicianAssignmentStatusEnum::Cancelled->value,
        ]);
    }
}
