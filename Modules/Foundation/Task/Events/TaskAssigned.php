<?php

namespace Modules\Foundation\Task\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\Task\Models\TaskAssignment;

class TaskAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Task $task;
    public TaskAssignment $assignment;

    public function __construct(Task $task, TaskAssignment $assignment)
    {
        $this->task = $task;
        $this->assignment = $assignment;
    }
}
