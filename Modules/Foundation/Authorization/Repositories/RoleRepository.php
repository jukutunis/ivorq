<?php

namespace Modules\Foundation\Authorization\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class RoleRepository
{
    public function allForProperty(?string $propertyId): Collection
    {
        return Role::when(
            $propertyId,
            fn($q) => $q->where('team_id', $propertyId),
            fn($q) => $q->whereNull('team_id')
        )->with('permissions')->get();
    }

    public function find(int $id): Role
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

    public function delete(int $id): bool
    {
        return Role::findOrFail($id)->delete();
    }
}
