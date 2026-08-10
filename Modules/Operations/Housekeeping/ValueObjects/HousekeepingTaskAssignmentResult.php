<?php

namespace Modules\Operations\Housekeeping\ValueObjects;

use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;

final readonly class HousekeepingTaskAssignmentResult
{
    public function __construct(
        public TaskAssignment $assignment,
        public CleaningTask $task,
        public string $userName,
        public string $departmentName,
        public bool $replayed,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assignment_id' => $this->assignment->id,
            'cleaning_task_id' => $this->task->id,
            'task_status' => $this->task->status instanceof \BackedEnum
                ? $this->task->status->value
                : (string) $this->task->status,
            'user_id' => $this->assignment->user_id,
            'user_name' => $this->userName,
            'department_id' => $this->assignment->department_id,
            'department_name' => $this->departmentName,
            'assignment_status' => $this->assignment->status instanceof \BackedEnum
                ? $this->assignment->status->value
                : (string) $this->assignment->status,
            'assignment_action' => $this->assignment->assignment_action,
            'previous_assignment_id' => $this->assignment->previous_assignment_id,
            'assigned_at' => $this->assignment->assigned_at?->toISOString(),
            'replayed' => $this->replayed,
        ];
    }
}
