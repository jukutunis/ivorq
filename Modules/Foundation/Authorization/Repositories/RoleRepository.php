<?php

namespace Modules\Foundation\Authorization\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Authorization\Models\Role;

class RoleRepository
{
    public function allForProperty(?string $propertyId): Collection
    {
        return Role::when(
            $propertyId,
            fn($q) => $q->where('property_id', $propertyId),
            fn($q) => $q->whereNull('property_id')
        )->with('permissions')->get();
    }

    public function find(string $id): Role
    {
        return Role::with('permissions')->findOrFail($id);
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function syncPermissions(Role $role, array $permissions): Role
    {
        $role->syncPermissions($permissions);

        return $role->fresh('permissions');
    }

    public function delete(string $id): bool
    {
        return Role::findOrFail($id)->delete();
    }
}
