<?php

namespace Modules\Foundation\Property\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Property\Contracts\PropertyRepositoryInterface;
use Modules\Foundation\Property\Models\Property;
use Shared\Exceptions\NotFoundException;

class PropertyRepository implements PropertyRepositoryInterface
{
    public function all(): Collection
    {
        return Property::withoutGlobalScope('property')
            ->with('company')
            ->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Property::withoutGlobalScope('property')
            ->with('company')
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id): Property
    {
        return Property::withoutGlobalScope('property')
            ->with('company')
            ->findOrFail($id);
    }

    public function findBySlug(string $slug): Property
    {
        $property = Property::withoutGlobalScope('property')
            ->where('slug', $slug)
            ->first();

        throw_if(!$property, new NotFoundException('Property'));

        return $property;
    }

    public function findByCode(string $code): Property
    {
        $property = Property::withoutGlobalScope('property')
            ->where('code', $code)
            ->first();

        throw_if(!$property, new NotFoundException('Property'));

        return $property;
    }

    public function create(array $data): Property
    {
        return Property::create($data);
    }

    public function update(string $id, array $data): Property
    {
        $property = $this->find($id);
        $property->update($data);

        return $property->fresh('company');
    }

    public function delete(string $id): bool
    {
        $property = $this->find($id);

        return $property->delete();
    }
}
