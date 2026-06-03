<?php

namespace Modules\Foundation\Department\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Modules\Foundation\Department\Http\Requests\StoreDepartmentRequest;
use Modules\Foundation\Department\Http\Requests\UpdateDepartmentRequest;
use Modules\Foundation\Department\Http\Resources\DepartmentResource;
use Modules\Foundation\Department\Services\DepartmentService;

class DepartmentController extends Controller
{
    public function __construct(
        private DepartmentService $departmentService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Department\Models\Department::class);

        $departments = $this->departmentService->paginate();

        return Inertia::render('Foundation/Department/Index', [
            'departments' => DepartmentResource::collection($departments),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', \Modules\Foundation\Department\Models\Department::class);

        return Inertia::render('Foundation/Department/Create');
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $department = $this->departmentService->create($request->validated());

        return redirect()->route('departments.show', $department->id)
            ->with('success', 'Department created successfully.');
    }

    public function show(string $id): Response
    {
        $department = $this->departmentService->find($id);
        $this->authorize('view', $department);

        return Inertia::render('Foundation/Department/Show', [
            'department' => new DepartmentResource($department),
        ]);
    }

    public function edit(string $id): Response
    {
        $department = $this->departmentService->find($id);
        $this->authorize('update', $department);

        return Inertia::render('Foundation/Department/Edit', [
            'department' => new DepartmentResource($department),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, string $id): RedirectResponse
    {
        $department = $this->departmentService->update($id, $request->validated());

        return redirect()->route('departments.show', $department->id)
            ->with('success', 'Department updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $department = $this->departmentService->find($id);
        $this->authorize('delete', $department);

        $this->departmentService->delete($id);

        return redirect()->route('departments.index')
            ->with('success', 'Department deleted successfully.');
    }
}
