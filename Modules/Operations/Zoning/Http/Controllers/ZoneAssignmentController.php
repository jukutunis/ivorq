<?php

namespace Modules\Operations\Zoning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Zoning\Http\Requests\EndZoneAssignmentRequest;
use Modules\Operations\Zoning\Http\Requests\ReassignZoneAssignmentRequest;
use Modules\Operations\Zoning\Http\Requests\StoreZoneAssignmentRequest;
use Modules\Operations\Zoning\Http\Requests\UpdateZoneAssignmentRequest;
use Modules\Operations\Zoning\Http\Resources\ZoneAssignmentResource;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Modules\Operations\Zoning\Repositories\ZoneAssignmentRepository;
use Modules\Operations\Zoning\Services\ZoneAssignmentService;
use Modules\Operations\Zoning\Services\ZoneService;
use Shared\Services\CurrentPropertyService;

class ZoneAssignmentController extends Controller
{
    public function __construct(
        private ZoneAssignmentService    $assignmentService,
        private ZoneAssignmentRepository $assignmentRepository,
        private ZoneService              $zoneService,
    ) {}

    public function index(string $zone): Response
    {
        $this->authorize('viewAny', ZoneAssignment::class);

        $assignments = $this->assignmentRepository->activeForZone($zone);

        return Inertia::render('Operations/Zone/Show', [
            'assignments' => ZoneAssignmentResource::collection($assignments),
        ]);
    }

    public function show(string $zone, string $assignment): Response
    {
        $model = $this->assignmentService->find($assignment);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Zone/Show', [
            'assignment' => new ZoneAssignmentResource($model->load(['zone', 'user', 'department'])),
        ]);
    }

    public function store(StoreZoneAssignmentRequest $request, string $zone): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'zone_id'     => $zone,
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $this->assignmentService->assign($data);

        return redirect()->route('operations.zones.show', $zone)
            ->with('success', 'Employee assigned successfully.');
    }

    public function update(UpdateZoneAssignmentRequest $request, string $zone, string $assignment): RedirectResponse
    {
        $this->assignmentService->update($assignment, $request->validated());

        return redirect()->route('operations.zones.show', $zone)
            ->with('success', 'Assignment updated successfully.');
    }

    public function destroy(string $zone, string $assignment): RedirectResponse
    {
        $model = $this->assignmentService->find($assignment);
        $this->authorize('delete', $model);

        $this->assignmentService->end($assignment);

        return redirect()->route('operations.zones.show', $zone)
            ->with('success', 'Assignment ended successfully.');
    }

    public function end(EndZoneAssignmentRequest $request, string $zone, string $assignment): RedirectResponse
    {
        $this->assignmentService->end($assignment);

        return redirect()->route('operations.zones.show', $zone)
            ->with('success', 'Assignment ended successfully.');
    }

    public function reassign(ReassignZoneAssignmentRequest $request, string $zone, string $assignment): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'zone_id'     => $zone,
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $this->assignmentService->reassign($assignment, $data);

        return redirect()->route('operations.zones.show', $zone)
            ->with('success', 'Employee reassigned successfully.');
    }
}
