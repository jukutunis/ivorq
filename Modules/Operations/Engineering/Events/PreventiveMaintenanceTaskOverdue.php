<?php

namespace Modules\Operations\Engineering\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;

class PreventiveMaintenanceTaskOverdue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PreventiveMaintenanceTask $task
    ) {}
}
