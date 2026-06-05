<?php

namespace Modules\Operations\PMS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Models\RatePlan;
use Shared\Exceptions\NotFoundException;

class RatePlanRepository
{
    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = RatePlan::latest();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['plan_type'])) {
            $query->where('plan_type', $filters['plan_type']);
        }

        return $query->paginate($perPage);
    }

    public function find(string $id): RatePlan
    {
        $ratePlan = RatePlan::find($id);

        throw_if(! $ratePlan, new NotFoundException('RatePlan'));

        return $ratePlan;
    }

    public function findOrFail(string $id): RatePlan
    {
        return RatePlan::findOrFail($id);
    }

    public function create(array $data): RatePlan
    {
        return RatePlan::create($data)->fresh();
    }

    public function update(string $id, array $data): RatePlan
    {
        $ratePlan = $this->find($id);
        $ratePlan->update($data);

        return $ratePlan->fresh();
    }

    public function delete(string $id): bool
    {
        return $this->find($id)->delete();
    }

    public function active(): Collection
    {
        return RatePlan::where('is_active', true)
            ->orderBy('rate_code')
            ->get();
    }

    public function findByCode(string $code): ?RatePlan
    {
        return RatePlan::where('rate_code', $code)->first();
    }
}
