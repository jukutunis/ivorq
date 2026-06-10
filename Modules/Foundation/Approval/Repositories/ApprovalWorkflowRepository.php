<?php

namespace Modules\Foundation\Approval\Repositories;

use Modules\Foundation\Approval\Models\ApprovalWorkflow;
class ApprovalWorkflowRepository
{
    public function __construct(protected ApprovalWorkflow $model)
    {
    }

    public function paginate(?array $filters = [], int $perPage = 15)
    {
        return $this->model->latest()->paginate($perPage);
    }

    public function create(array $data): ApprovalWorkflow
    {
        return $this->model->create($data);
    }

    public function update(string $id, array $data): ApprovalWorkflow
    {
        $workflow = $this->model->findOrFail($id);
        $workflow->update($data);
        return $workflow;
    }

    public function delete(string $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }

    public function findMatchingWorkflow(string $propertyId, string $module): ?ApprovalWorkflow
    {
        return $this->model
            ->where('property_id', $propertyId)
            ->where('module', $module)
            ->where('is_active', true)
            ->first();
    }
}
