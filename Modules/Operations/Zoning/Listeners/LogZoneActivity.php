<?php

namespace Modules\Operations\Zoning\Listeners;

use Modules\Foundation\Activity\Services\ActivityService;
use Modules\Operations\Zoning\Events\ZoneAssigned;
use Modules\Operations\Zoning\Events\ZoneAssignmentEnded;
use Modules\Operations\Zoning\Events\ZoneCreated;
use Modules\Operations\Zoning\Events\ZoneReassigned;
use Modules\Operations\Zoning\Events\ZoneStatusChanged;

class LogZoneActivity
{
    public function __construct(
        private ActivityService $activityService
    ) {}

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
        $this->activityService->log(
            description: "Zone [{$event->zone->zone_code}] {$event->zone->zone_name} was created",
            subject: $event->zone,
        );
    }

    private function onZoneStatusChanged(ZoneStatusChanged $event): void
    {
        $this->activityService->log(
            description: "Zone [{$event->zone->zone_code}] {$event->zone->zone_name} status changed"
                . " from {$event->from->label()} to {$event->to->label()}",
            subject: $event->zone,
        );
    }

    private function onZoneAssigned(ZoneAssigned $event): void
    {
        $userName = $event->assignment->user?->name ?? $event->assignment->user_id;
        $zone     = $event->assignment->zone;

        $this->activityService->log(
            description: "Employee {$userName} was assigned to zone [{$zone?->zone_code}] {$zone?->zone_name}",
            subject: $zone,
        );
    }

    private function onZoneReassigned(ZoneReassigned $event): void
    {
        $userName = $event->newAssignment->user?->name ?? $event->newAssignment->user_id;
        $zone     = $event->newAssignment->zone;

        $this->activityService->log(
            description: "Employee {$userName} was reassigned to zone [{$zone?->zone_code}] {$zone?->zone_name}",
            subject: $zone,
        );
    }

    private function onZoneAssignmentEnded(ZoneAssignmentEnded $event): void
    {
        $userName = $event->assignment->user?->name ?? $event->assignment->user_id;
        $zone     = $event->assignment->zone;

        $this->activityService->log(
            description: "Assignment of {$userName} in zone [{$zone?->zone_code}] {$zone?->zone_name} was ended",
            subject: $zone,
        );
    }
}
