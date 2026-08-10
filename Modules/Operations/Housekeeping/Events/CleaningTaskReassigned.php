<?php

namespace Modules\Operations\Housekeeping\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\TaskAssignment;

class CleaningTaskReassigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CleaningTask $task,
        public readonly TaskAssignment $assignment,
        public readonly string $previousAssignmentId,
    ) {}
}
