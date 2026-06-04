<?php

namespace Modules\Operations\Housekeeping\Listeners;

use Modules\Operations\Housekeeping\Events\RoomCreated;
use Modules\Operations\Housekeeping\Events\RoomStatusChanged;
use Modules\Operations\Housekeeping\Models\RoomStatusHistory;

class RecordRoomHistory
{
    public function handle(RoomCreated|RoomStatusChanged $event): void
    {
        match (true) {
            $event instanceof RoomCreated       => $this->onRoomCreated($event),
            $event instanceof RoomStatusChanged => $this->onRoomStatusChanged($event),
        };
    }

    private function onRoomCreated(RoomCreated $event): void
    {
        // Record the room's initial cleanliness state. Occupancy starts as null
        // (untracked) so no occupancy history entry is needed at creation.
        RoomStatusHistory::record([
            'property_id'  => $event->room->property_id,
            'room_id'      => $event->room->id,
            'status_field' => 'cleanliness',
            'from_status'  => null,
            'to_status'    => $event->room->cleanliness_status->value,
            'action'       => 'room_created',
            'performed_by' => auth()->id(),
            'remarks'      => null,
        ]);
    }

    private function onRoomStatusChanged(RoomStatusChanged $event): void
    {
        RoomStatusHistory::record([
            'property_id'  => $event->room->property_id,
            'room_id'      => $event->room->id,
            'status_field' => $event->statusField,
            'from_status'  => $event->from,
            'to_status'    => $event->to,
            'action'       => $this->resolveAction($event->statusField, $event->to),
            'performed_by' => auth()->id(),
            'remarks'      => $event->remarks,
        ]);
    }

    private function resolveAction(string $statusField, ?string $to): string
    {
        if ($statusField === 'cleanliness') {
            return match ($to) {
                'clean'     => 'room_cleaned',
                'inspected' => 'room_inspected',
                'dirty'     => 'room_soiled',
                default     => 'cleanliness_changed',
            };
        }

        if ($statusField === 'occupancy') {
            return match ($to) {
                'occupied' => 'room_occupied',
                'vacant'   => 'room_vacated',
                'blocked'  => 'room_blocked',
                default    => 'occupancy_changed',
            };
        }

        return 'status_changed';
    }
}
