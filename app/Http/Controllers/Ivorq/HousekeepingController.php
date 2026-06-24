<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

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
}
