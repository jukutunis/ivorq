<?php

namespace Modules\Operations\WorkOrder\DTOs;

use Illuminate\Http\Request;

class WorkOrderAssignmentDTO
{
    public function __construct(
        public readonly string $workOrderId,
        public readonly ?string $userId = null,
        public readonly ?string $departmentId = null,
    ) {}

    public static function fromRequest(string $workOrderId, Request $request): self
    {
        return new self(
            workOrderId: $workOrderId,
            userId: $request->input('user_id'),
            departmentId: $request->input('department_id'),
        );
    }
}
