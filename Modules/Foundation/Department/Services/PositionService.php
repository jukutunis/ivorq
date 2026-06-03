<?php

namespace Modules\Foundation\Department\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Department\Models\Position;
use Modules\Foundation\Department\Repositories\PositionRepository;

class PositionService
{
    public function __construct(
        private PositionRepository $positionRepository
    ) {}

    public function allForDepartment(string $departmentId): Collection
    {
        return $this->positionRepository->allForDepartment($departmentId);
    }

    public function find(string $id): Position
    {
        return $this->positionRepository->find($id);
    }

    public function create(array $data): Position
    {
        return $this->positionRepository->create($data);
    }

    public function update(string $id, array $data): Position
    {
        return $this->positionRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->positionRepository->delete($id);
    }
}
