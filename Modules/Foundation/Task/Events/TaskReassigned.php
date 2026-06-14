<?php

namespace Modules\Foundation\Task\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\Task\Models\TaskAssignment;

class TaskReassigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Task $task;
    public TaskAssignment $oldAssignment;
    public TaskAssignment $newAssignment;

    public function __construct(Task $task, TaskAssignment $oldAssignment, TaskAssignment $newAssignment)
    {
        $this->task = $task;
        $this->oldAssignment = $oldAssignment;
        $this->newAssignment = $newAssignment;
    }
}
