<?php

namespace Modules\Operations\Purchasing\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;

class VendorRepository
{
    public function __construct(private CurrentPropertyService $currentProperty) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->companyQuery()->with(['category', 'contacts'])->latest();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }

        if (! empty($filters['vendor_code'])) {
            $query->where('vendor_code', 'like', '%'.$filters['vendor_code'].'%');
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
        $vendor = $this->companyQuery()->with(['category', 'contacts'])->find($id);

        throw_if(! $vendor, new NotFoundException('Vendor'));

        return $vendor;
    }

    public function findOrFail(string $id): Vendor
    {
        return $this->companyQuery()->findOrFail($id);
    }

    public function create(array $data): Vendor
    {
        $data['company_id'] = $this->currentCompanyId();

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

    private function companyQuery(): Builder
    {
        return Vendor::query()->forCompany($this->currentCompanyId());
    }

    private function currentCompanyId(): string
    {
        return (string) Property::query()
            ->whereKey($this->currentProperty->resolveOrFail())
            ->valueOrFail('company_id');
    }
}
