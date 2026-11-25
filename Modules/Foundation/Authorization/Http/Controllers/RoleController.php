<?php

namespace Modules\Foundation\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authorization\Http\Requests\StoreRoleRequest;
use Modules\Foundation\Authorization\Http\Requests\UpdateRoleRequest;
use Modules\Foundation\Authorization\Http\Resources\PermissionResource;
use Modules\Foundation\Authorization\Http\Resources\RoleResource;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Services\PermissionService;
use Modules\Foundation\Authorization\Services\RoleService;
use Shared\Services\CurrentPropertyService;

class RoleController extends Controller
{
    public function __construct(
        private RoleService $roleService,
        private PermissionService $permissionService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $roles = $this->roleService->allForProperty($propertyId);

        return Inertia::render('Foundation/Role/Index', [
            'roles' => RoleResource::collection($roles),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Foundation/Role/Create', [
            'permissions' => PermissionResource::collection(
                $this->permissionService->all()
            ),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $role = $this->roleService->create($request->name, $propertyId);

        if ($request->filled('permissions')) {
            $this->roleService->syncPermissionsForProperty($role->id, $propertyId, $request->permissions);
        }

        return redirect()->route('roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function edit(string $id): Response
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $role = $this->roleService->findForProperty($id, $propertyId);
        $this->authorize('update', $role);

        return Inertia::render('Foundation/Role/Edit', [
            'role' => new RoleResource($role),
            'permissions' => PermissionResource::collection(
                $this->permissionService->all()
            ),
        ]);
    }

    public function update(UpdateRoleRequest $request, string $id): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $role = $this->roleService->findForProperty($id, $propertyId);
        $this->authorize('update', $role);
        $this->roleService->syncPermissionsForProperty($id, $propertyId, $request->permissions);

        return redirect()->route('roles.index')
            ->with('success', 'Role permissions updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $role = $this->roleService->findForProperty($id, $propertyId);
        $this->authorize('delete', $role);

        $this->roleService->deleteForProperty($id, $propertyId);

        return redirect()->route('roles.index')
            ->with('success', 'Role deleted successfully.');
    }
}
