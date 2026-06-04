<?php

namespace Modules\Operations\Housekeeping\Observers;

use Modules\Foundation\Audit\Services\AuditService;
use Modules\Operations\Housekeeping\Models\CleaningTask;

class CleaningTaskObserver
{
    public function __construct(
        private AuditService $auditService
    ) {}

    public function created(CleaningTask $task): void
    {
        $this->auditService->log(
            event: 'created',
            model: $task,
            newValues: $task->getAttributes(),
        );
    }

    public function updated(CleaningTask $task): void
    {
        $this->auditService->log(
            event: 'updated',
            model: $task,
            oldValues: $task->getOriginal(),
            newValues: $task->getChanges(),
        );
    }

    public function deleted(CleaningTask $task): void
    {
        $this->auditService->log(
            event: 'deleted',
            model: $task,
            oldValues: $task->getAttributes(),
        );
    }
}
