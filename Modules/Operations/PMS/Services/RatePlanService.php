<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Repositories\RatePlanRepository;

class RatePlanService
{
    public function __construct(
        private RatePlanRepository $ratePlanRepository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->ratePlanRepository->paginate($filters, $perPage);
    }

    public function find(string $id): RatePlan
    {
        return $this->ratePlanRepository->find($id);
    }

    public function create(array $data): RatePlan
    {
        return $this->ratePlanRepository->create($data);
    }

    public function update(string $id, array $data): RatePlan
    {
        return $this->ratePlanRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->ratePlanRepository->delete($id);
    }

    public function active(): Collection
    {
        return $this->ratePlanRepository->active();
    }

    public function findByCode(string $code): ?RatePlan
    {
        return $this->ratePlanRepository->findByCode($code);
    }
}
