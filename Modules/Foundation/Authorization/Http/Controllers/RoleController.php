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
use Modules\Foundation\Authorization\Services\PermissionService;
use Modules\Foundation\Authorization\Services\RoleService;

class RoleController extends Controller
{
    public function __construct(
        private RoleService $roleService,
        private PermissionService $permissionService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', \Spatie\Permission\Models\Role::class);

        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();
        $roles      = $this->roleService->allForProperty($propertyId);

        return Inertia::render('Foundation/Role/Index', [
            'roles' => RoleResource::collection($roles),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', \Spatie\Permission\Models\Role::class);

        return Inertia::render('Foundation/Role/Create', [
            'permissions' => PermissionResource::collection(
                $this->permissionService->all()
            ),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();
        $role       = $this->roleService->create($request->name, $propertyId);

        if ($request->filled('permissions')) {
            $this->roleService->syncPermissions($role->id, $request->permissions);
        }

        return redirect()->route('roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function edit(int $id): Response
    {
        $this->authorize('update', \Spatie\Permission\Models\Role::class);

        return Inertia::render('Foundation/Role/Edit', [
            'role'        => new RoleResource($this->roleService->find($id)),
            'permissions' => PermissionResource::collection(
                $this->permissionService->all()
            ),
        ]);
    }

    public function update(UpdateRoleRequest $request, int $id): RedirectResponse
    {
        $this->roleService->syncPermissions($id, $request->permissions);

        return redirect()->route('roles.index')
            ->with('success', 'Role permissions updated successfully.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->authorize('delete', \Spatie\Permission\Models\Role::class);

        $this->roleService->delete($id);

        return redirect()->route('roles.index')
            ->with('success', 'Role deleted successfully.');
    }
}
