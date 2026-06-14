<?php

namespace Modules\Foundation\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authorization\Http\Resources\PermissionResource;
use Modules\Foundation\Authorization\Services\PermissionService;

class PermissionController extends Controller
{
    public function __construct(
        private PermissionService $permissionService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Authorization\Models\Role::class);

        $permissions = $this->permissionService->all();

        return Inertia::render('Foundation/Permission/Index', [
            'permissions' => PermissionResource::collection($permissions),
            'grouped'     => $this->permissionService->grouped(),
        ]);
    }
}
