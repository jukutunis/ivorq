<?php

namespace Modules\Foundation\Department\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Department\Models\Department;
use Shared\Exceptions\NotFoundException;

class DepartmentRepository
{
    public function all(): Collection
    {
        return Department::with('positions')->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Department::withCount('positions')->latest()->paginate($perPage);
    }

    public function find(string $id): Department
    {
        $department = Department::with('positions')->find($id);

        throw_if(!$department, new NotFoundException('Department'));

        return $department;
    }

    public function create(array $data): Department
    {
        return Department::create($data);
    }

    public function update(string $id, array $data): Department
    {
        $department = $this->find($id);
        $department->update($data);

        return $department->fresh();
    }

    public function delete(string $id): bool
    {
        $department = $this->find($id);

        return $department->delete();
    }
}
