<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Http\Resources\CleaningTaskResource;
use Modules\Operations\Housekeeping\Http\Resources\RoomInspectionResource;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;

class HousekeepingDashboardController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Room::class);

        return Inertia::render('Operations/Housekeeping/Dashboard/Index', [
            'stats' => [
                'total_rooms'         => Room::count(),
                'dirty_rooms'         => Room::where('cleanliness_status', 'dirty')->count(),
                'clean_rooms'         => Room::where('cleanliness_status', 'clean')->count(),
                'pending_tasks'       => CleaningTask::where('status', 'pending')->count(),
                'in_progress_tasks'   => CleaningTask::where('status', 'in_progress')->count(),
                'pending_inspections' => RoomInspection::where('status', 'pending')->count(),
            ],
            'todays_tasks' => CleaningTaskResource::collection(
                CleaningTask::with(['room', 'zone'])
                    ->whereDate('due_date', today())
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->orderBy('priority')
                    ->limit(10)
                    ->get()
            ),
            'failed_inspections' => RoomInspectionResource::collection(
                RoomInspection::with(['room', 'inspector'])
                    ->where('status', 'failed')
                    ->latest('inspected_at')
                    ->limit(10)
                    ->get()
            ),
        ]);
    }
}
