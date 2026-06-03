<?php

namespace Modules\Foundation\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Activity\Http\Resources\ActivityLogResource;
use Modules\Foundation\Activity\Repositories\ActivityLogRepository;

class ActivityLogController extends Controller
{
    public function __construct(
        private ActivityLogRepository $activityLogRepository
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Activity\Models\ActivityLog::class);

        $logs = $this->activityLogRepository->paginate(
            filters: $request->only(['user_id', 'subject_type', 'subject_id', 'from', 'to']),
            perPage: $request->integer('per_page', 30)
        );

        return Inertia::render('Foundation/ActivityLog/Index', [
            'logs'    => ActivityLogResource::collection($logs),
            'filters' => $request->only(['user_id', 'subject_type', 'from', 'to']),
        ]);
    }

    public function show(string $id): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Activity\Models\ActivityLog::class);

        $log = $this->activityLogRepository->find($id);

        return Inertia::render('Foundation/ActivityLog/Show', [
            'log' => new ActivityLogResource($log),
        ]);
    }
}
