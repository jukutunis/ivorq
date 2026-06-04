<?php

namespace Modules\Operations\Zoning\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Exceptions\NotFoundException;

class ZoneRepository
{
    public function all(): Collection
    {
        return Zone::with('activeAssignments')->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Zone::withCount('assignments')->latest()->paginate($perPage);
    }

    public function find(string $id): Zone
    {
        $zone = Zone::with(['activeAssignments.user', 'activeAssignments.department'])->find($id);

        throw_if(! $zone, new NotFoundException('Zone'));

        return $zone;
    }

    public function findOrFail(string $id): Zone
    {
        return Zone::findOrFail($id);
    }

    public function create(array $data): Zone
    {
        return Zone::create($data)->fresh();
    }

    public function update(string $id, array $data): Zone
    {
        $zone = $this->find($id);
        $zone->update($data);

        return $zone->fresh();
    }

    public function delete(string $id): bool
    {
        $zone = $this->find($id);

        return $zone->delete();
    }

    public function byStatus(ZoneStatusEnum $status): Collection
    {
        return Zone::where('status', $status)->get();
    }

    public function activeForProperty(): Collection
    {
        return Zone::where('status', ZoneStatusEnum::Active)->get();
    }
}
