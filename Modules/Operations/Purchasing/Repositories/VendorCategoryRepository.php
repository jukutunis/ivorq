<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Shared\Exceptions\NotFoundException;

class VendorCategoryRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = VendorCategory::latest();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): VendorCategory
    {
        $category = VendorCategory::find($id);

        throw_if(! $category, new NotFoundException('VendorCategory'));

        return $category;
    }

    public function findOrFail(string $id): VendorCategory
    {
        return VendorCategory::findOrFail($id);
    }

    public function create(array $data): VendorCategory
    {
        return VendorCategory::create($data)->fresh();
    }

    public function update(string $id, array $data): VendorCategory
    {
        $category = $this->find($id);
        $category->update($data);

        return $category->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return VendorCategory::where('is_active', true)->orderBy('name')->get();
    }
}
