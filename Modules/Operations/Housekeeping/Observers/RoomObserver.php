<?php

namespace Modules\Operations\Housekeeping\Observers;

use Modules\Foundation\Audit\Services\AuditService;
use Modules\Operations\Housekeeping\Models\Room;

class RoomObserver
{
    public function __construct(
        private AuditService $auditService
    ) {}

    public function created(Room $room): void
    {
        $this->auditService->log(
            event: 'created',
            model: $room,
            newValues: $room->getAttributes(),
        );
    }

    public function updated(Room $room): void
    {
        $this->auditService->log(
            event: 'updated',
            model: $room,
            oldValues: $room->getOriginal(),
            newValues: $room->getChanges(),
        );
    }

    public function deleted(Room $room): void
    {
        $this->auditService->log(
            event: 'deleted',
            model: $room,
            oldValues: $room->getAttributes(),
        );
    }
}
