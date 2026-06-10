<?php

namespace Modules\Foundation\Approval\Repositories;

use Modules\Foundation\Approval\Models\ApprovalStep;
class ApprovalStepRepository
{
    public function __construct(protected ApprovalStep $model)
    {
    }

    public function create(array $data): ApprovalStep
    {
        return $this->model->create($data);
    }
}
