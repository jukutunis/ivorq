<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

class PermissionService
{
    public function all(): Collection
    {
        return Permission::orderBy('name')->get();
    }

    public function grouped(): array
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn($p) => explode('.', $p->name)[0])
            ->toArray();
    }

    public function create(string $name): Permission
    {
        return Permission::create(['name' => $name, 'guard_name' => 'web']);
    }

    public function delete(int $id): bool
    {
        return Permission::findOrFail($id)->delete();
    }
}
