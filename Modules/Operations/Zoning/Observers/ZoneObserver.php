<?php

namespace Modules\Operations\Zoning\Observers;

use Modules\Foundation\Audit\Services\AuditService;
use Modules\Operations\Zoning\Models\Zone;

class ZoneObserver
{
    public function __construct(
        private AuditService $auditService
    ) {}

    public function created(Zone $zone): void
    {
        $this->auditService->log(
            event: 'created',
            model: $zone,
            newValues: $zone->getAttributes(),
        );
    }

    public function updated(Zone $zone): void
    {
        $this->auditService->log(
            event: 'updated',
            model: $zone,
            oldValues: $zone->getOriginal(),
            newValues: $zone->getChanges(),
        );
    }

    public function deleted(Zone $zone): void
    {
        $this->auditService->log(
            event: 'deleted',
            model: $zone,
            oldValues: $zone->getAttributes(),
        );
    }
}
