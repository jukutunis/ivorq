<?php

namespace Modules\Operations\Housekeeping\Services;

use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum;

class TaskAssignmentService
{
    public function complete(string $assignmentId): TaskAssignment
    {
        $assignment = TaskAssignment::findOrFail($assignmentId);
        $assignment->update([
            'status' => AssignmentStatusEnum::Completed,
            'completed_at' => now(),
        ]);
        return $assignment;
    }

    public function cancel(string $assignmentId): TaskAssignment
    {
        $assignment = TaskAssignment::findOrFail($assignmentId);
        $assignment->update([
            'status' => AssignmentStatusEnum::Cancelled,
        ]);
        return $assignment;
    }
}