<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;

class HousekeepingController extends Controller
{
    private function renderWorkspace(string $activeTab)
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($propertyId);

        $rooms = \Modules\Operations\Housekeeping\Models\Room::where('property_id', $propertyId)
            ->with(['zone'])
            ->get();

        $tasks = \Modules\Operations\Housekeeping\Models\CleaningTask::where('property_id', $propertyId)
            ->with(['room.zone', 'assignments.user', 'inspections.inspector'])
            ->get();

        $attendants = \Modules\Foundation\User\Models\User::whereHas('properties', function ($q) use ($propertyId) {
            $q->where('properties.id', $propertyId);
        })->get();

        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace', [
            'activeTab' => $activeTab,
            'rooms'     => $rooms,
            'tasks'     => $tasks,
            'attendants'=> $attendants,
            'auth_user' => auth()->user(),
        ]);
    }

    public function roomBoard()
    {
        return $this->renderWorkspace('room_board');
    }

    public function attendantStatus()
    {
        return $this->renderWorkspace('attendant_status');
    }

    public function inspections()
    {
        return $this->renderWorkspace('inspections');
    }

    public function lostFound()
    {
        return $this->renderWorkspace('lost_found');
    }

    public function roomReadiness()
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($propertyId);

        $rooms = \Modules\Operations\Housekeeping\Models\Room::where('property_id', $propertyId)
            ->where('is_active', true)
            ->with(['zone'])
            ->get();

        $readinessRows = $rooms->map(function ($room) {
            $readinessState = (string) ($room->readiness_state ?? 'unknown');
            $cleanlinessStatus = $room->cleanliness_status instanceof \BackedEnum
                ? $room->cleanliness_status->value
                : (string) $room->cleanliness_status;

            return [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'room_type' => $room->room_type instanceof \BackedEnum ? $room->room_type->value : (string) $room->room_type,
                'floor' => $room->floor,
                'zone' => $room->zone?->name,
                'cleanliness_status' => $cleanlinessStatus,
                'readiness_state' => $readinessState,
                'is_vip' => $room->is_vip,
            ];
        })->values()->all();

        return Inertia::render('Ivorq/Housekeeping/HousekeepingWorkspace', [
            'activeTab' => 'room_readiness',
            'rooms' => $rooms,
            'readinessRows' => $readinessRows,
            'auth_user' => auth()->user(),
        ]);
    }
}
