<?php

namespace Modules\Foundation\Task\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\Task\Models\Task;

class TaskCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Task $task;

    public function __construct(Task $task)
    {
        $this->task = $task;
    }
}
