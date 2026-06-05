<?php

namespace Modules\Operations\Engineering\Listeners;

use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskCompleted;

class UpdatePreventiveMaintenanceSchedule
{
    public function handle(PreventiveMaintenanceTaskCompleted $event): void
    {
        $task = $event->task;
        $pm   = $task->preventiveMaintenance;

        if (! $pm) {
            return;
        }

        $lastRunAt = $task->completed_at ?? now();

        // intervalDays() returns null for PmFrequencyEnum::Custom —
        // fall back to the PM's explicit frequency_days column.
        $intervalDays = $pm->frequency->intervalDays()
            ?? $pm->frequency_days;

        $nextDueAt = $intervalDays
            ? $lastRunAt->copy()->addDays($intervalDays)
            : null;

        $pm->update([
            'last_run_at' => $lastRunAt,
            'next_due_at' => $nextDueAt,
        ]);
    }
}
