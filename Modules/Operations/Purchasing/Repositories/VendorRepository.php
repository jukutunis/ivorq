<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Exceptions\NotFoundException;

class VendorRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Vendor::with(['category', 'contacts'])->latest();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (! empty($filters['vendor_code'])) {
            $query->where('vendor_code', 'like', '%' . $filters['vendor_code'] . '%');
        }

        if (! empty($filters['vendor_category_id'])) {
            $query->where('vendor_category_id', $filters['vendor_category_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['is_approved'])) {
            $query->where('is_approved', $filters['is_approved']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): Vendor
    {
        $vendor = Vendor::with(['category', 'contacts'])->find($id);

        throw_if(! $vendor, new NotFoundException('Vendor'));

        return $vendor;
    }

    public function findOrFail(string $id): Vendor
    {
        return Vendor::findOrFail($id);
    }

    public function create(array $data): Vendor
    {
        return Vendor::create($data)->fresh(['category', 'contacts']);
    }

    public function update(string $id, array $data): Vendor
    {
        $vendor = $this->find($id);
        $vendor->update($data);

        return $vendor->fresh(['category', 'contacts']);
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }
}
