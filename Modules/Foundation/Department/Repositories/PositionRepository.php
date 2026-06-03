<?php

namespace Modules\Foundation\Department\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Department\Models\Position;
use Shared\Exceptions\NotFoundException;

class PositionRepository
{
    public function allForDepartment(string $departmentId): Collection
    {
        return Position::where('department_id', $departmentId)
            ->orderBy('level')
            ->get();
    }

    public function find(string $id): Position
    {
        $position = Position::with('department')->find($id);

        throw_if(!$position, new NotFoundException('Position'));

        return $position;
    }

    public function create(array $data): Position
    {
        return Position::create($data);
    }

    public function update(string $id, array $data): Position
    {
        $position = $this->find($id);
        $position->update($data);

        return $position->fresh();
    }

    public function delete(string $id): bool
    {
        $position = $this->find($id);

        return $position->delete();
    }
}
