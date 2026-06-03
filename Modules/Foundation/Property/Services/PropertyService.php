<?php

namespace Modules\Foundation\Property\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Contracts\PropertyRepositoryInterface;
use Modules\Foundation\Property\Events\PropertyCreated;
use Modules\Foundation\Property\Events\PropertyUpdated;
use Modules\Foundation\Property\Models\Property;

class PropertyService
{
    public function __construct(
        private PropertyRepositoryInterface $propertyRepository
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->propertyRepository->paginate($perPage);
    }

    public function find(string $id): Property
    {
        return $this->propertyRepository->find($id);
    }

    public function create(array $data): Property
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $property = $this->propertyRepository->create($data);

        event(new PropertyCreated($property));

        return $property;
    }

    public function update(string $id, array $data): Property
    {
        $property = $this->propertyRepository->update($id, $data);

        event(new PropertyUpdated($property));

        return $property;
    }

    public function delete(string $id): bool
    {
        return $this->propertyRepository->delete($id);
    }
}
