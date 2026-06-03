<?php

namespace Modules\Foundation\Department\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Department\Http\Requests\StorePositionRequest;
use Modules\Foundation\Department\Http\Requests\UpdatePositionRequest;
use Modules\Foundation\Department\Http\Resources\PositionResource;
use Modules\Foundation\Department\Services\DepartmentService;
use Modules\Foundation\Department\Services\PositionService;

class PositionController extends Controller
{
    public function __construct(
        private PositionService $positionService,
        private DepartmentService $departmentService
    ) {}

    public function index(string $departmentId): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Department\Models\Department::class);

        $department = $this->departmentService->find($departmentId);
        $positions  = $this->positionService->allForDepartment($departmentId);

        return Inertia::render('Foundation/Position/Index', [
            'department' => $department,
            'positions'  => PositionResource::collection($positions),
        ]);
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $position = $this->positionService->create($request->validated());

        return redirect()->route('departments.show', $position->department_id)
            ->with('success', 'Position created successfully.');
    }

    public function show(string $id): Response
    {
        $position = $this->positionService->find($id);
        $this->authorize('view', $position->department);

        return Inertia::render('Foundation/Position/Show', [
            'position' => new PositionResource($position),
        ]);
    }

    public function update(UpdatePositionRequest $request, string $id): RedirectResponse
    {
        $position = $this->positionService->update($id, $request->validated());

        return redirect()->route('departments.show', $position->department_id)
            ->with('success', 'Position updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $position = $this->positionService->find($id);
        $this->authorize('delete', $position->department);

        $departmentId = $position->department_id;
        $this->positionService->delete($id);

        return redirect()->route('departments.show', $departmentId)
            ->with('success', 'Position deleted successfully.');
    }
}
