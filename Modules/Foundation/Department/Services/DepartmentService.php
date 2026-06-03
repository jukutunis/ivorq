<?php

namespace Modules\Foundation\Department\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Repositories\DepartmentRepository;

class DepartmentService
{
    public function __construct(
        private DepartmentRepository $departmentRepository
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->departmentRepository->paginate($perPage);
    }

    public function find(string $id): Department
    {
        return $this->departmentRepository->find($id);
    }

    public function create(array $data): Department
    {
        return $this->departmentRepository->create($data);
    }

    public function update(string $id, array $data): Department
    {
        return $this->departmentRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->departmentRepository->delete($id);
    }
}
