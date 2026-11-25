<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Repositories\RoleRepository;

class RoleService
{
    public function __construct(
        private RoleRepository $roleRepository
    ) {}

    public function allForProperty(?string $propertyId = null): Collection
    {
        return $this->roleRepository->allForProperty($propertyId);
    }

    public function findForProperty(string $id, string $propertyId): Role
    {
        return $this->roleRepository->findForProperty($id, $propertyId);
    }

    public function create(string $name, ?string $propertyId = null): Role
    {
        setPermissionsTeamId($propertyId);

        return $this->roleRepository->create([
            'name' => $name,
            'guard_name' => 'web',
            'property_id' => $propertyId,
        ]);
    }

    public function syncPermissionsForProperty(string $roleId, string $propertyId, array $permissions): Role
    {
        $role = $this->findForProperty($roleId, $propertyId);

        return $this->roleRepository->syncPermissions($role, $permissions);
    }

    public function deleteForProperty(string $id, string $propertyId): bool
    {
        return $this->roleRepository->deleteForProperty($id, $propertyId);
    }
}
