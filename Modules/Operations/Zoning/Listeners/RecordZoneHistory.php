<?php

namespace Modules\Operations\Zoning\Listeners;

use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Events\ZoneAssigned;
use Modules\Operations\Zoning\Events\ZoneAssignmentEnded;
use Modules\Operations\Zoning\Events\ZoneCreated;
use Modules\Operations\Zoning\Events\ZoneReassigned;
use Modules\Operations\Zoning\Events\ZoneStatusChanged;
use Modules\Operations\Zoning\Models\ZoneHistory;

class RecordZoneHistory
{
    public function handle(
        ZoneCreated|ZoneStatusChanged|ZoneAssigned|ZoneReassigned|ZoneAssignmentEnded $event
    ): void {
        match (true) {
            $event instanceof ZoneCreated         => $this->onZoneCreated($event),
            $event instanceof ZoneStatusChanged   => $this->onZoneStatusChanged($event),
            $event instanceof ZoneAssigned        => $this->onZoneAssigned($event),
            $event instanceof ZoneReassigned      => $this->onZoneReassigned($event),
            $event instanceof ZoneAssignmentEnded => $this->onZoneAssignmentEnded($event),
        };
    }

    private function onZoneCreated(ZoneCreated $event): void
    {
        ZoneHistory::record([
            'property_id'  => $event->zone->property_id,
            'zone_id'      => $event->zone->id,
            'action'       => 'zone_created',
            'performed_by' => auth()->id(),
            'remarks'      => null,
        ]);
    }

    private function onZoneStatusChanged(ZoneStatusChanged $event): void
    {
        $action = match ($event->to) {
            ZoneStatusEnum::Active    => 'zone_activated',
            ZoneStatusEnum::Suspended => 'zone_suspended',
            ZoneStatusEnum::Archived  => 'zone_archived',
            default                   => 'zone_updated',
        };

        ZoneHistory::record([
            'property_id'  => $event->zone->property_id,
            'zone_id'      => $event->zone->id,
            'action'       => $action,
            'performed_by' => auth()->id(),
            'remarks'      => $event->remarks,
        ]);
    }

    private function onZoneAssigned(ZoneAssigned $event): void
    {
        ZoneHistory::record([
            'property_id'  => $event->assignment->property_id,
            'zone_id'      => $event->assignment->zone_id,
            'action'       => 'employee_assigned',
            'performed_by' => auth()->id(),
            'remarks'      => null,
        ]);
    }

    private function onZoneReassigned(ZoneReassigned $event): void
    {
        ZoneHistory::record([
            'property_id'  => $event->newAssignment->property_id,
            'zone_id'      => $event->newAssignment->zone_id,
            'action'       => 'employee_reassigned',
            'performed_by' => auth()->id(),
            'remarks'      => null,
        ]);
    }

    private function onZoneAssignmentEnded(ZoneAssignmentEnded $event): void
    {
        ZoneHistory::record([
            'property_id'  => $event->assignment->property_id,
            'zone_id'      => $event->assignment->zone_id,
            'action'       => 'assignment_ended',
            'performed_by' => auth()->id(),
            'remarks'      => null,
        ]);
    }
}
