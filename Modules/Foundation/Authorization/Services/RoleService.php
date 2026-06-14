<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Authorization\Repositories\RoleRepository;
use Modules\Foundation\Authorization\Models\Role;

class RoleService
{
    public function __construct(
        private RoleRepository $roleRepository
    ) {}

    public function allForProperty(?string $propertyId = null): Collection
    {
        return $this->roleRepository->allForProperty($propertyId);
    }

    public function find(string $id): Role
    {
        return $this->roleRepository->find($id);
    }

    public function create(string $name, ?string $propertyId = null): Role
    {
        setPermissionsTeamId($propertyId);

        return $this->roleRepository->create([
            'name'       => $name,
            'guard_name' => 'web',
            'property_id'    => $propertyId,
        ]);
    }

    public function syncPermissions(string $roleId, array $permissions): Role
    {
        $role = $this->find($roleId);

        return $this->roleRepository->syncPermissions($role, $permissions);
    }

    public function delete(string $id): bool
    {
        return $this->roleRepository->delete($id);
    }
}
