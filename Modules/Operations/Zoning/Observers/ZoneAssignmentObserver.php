<?php

namespace Modules\Operations\Zoning\Observers;

use Modules\Foundation\Audit\Services\AuditService;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class ZoneAssignmentObserver
{
    public function __construct(
        private AuditService $auditService
    ) {}

    public function created(ZoneAssignment $assignment): void
    {
        $this->auditService->log(
            event: 'created',
            model: $assignment,
            newValues: $assignment->getAttributes(),
        );
    }

    public function updated(ZoneAssignment $assignment): void
    {
        $this->auditService->log(
            event: 'updated',
            model: $assignment,
            oldValues: $assignment->getOriginal(),
            newValues: $assignment->getChanges(),
        );
    }

    public function deleted(ZoneAssignment $assignment): void
    {
        $this->auditService->log(
            event: 'deleted',
            model: $assignment,
            oldValues: $assignment->getAttributes(),
        );
    }
}
