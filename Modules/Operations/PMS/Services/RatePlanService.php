<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Models\RatePlan;

class RatePlanService
{
    private const WRITABLE = ['rate_code', 'rate_name', 'plan_type', 'base_rate',
        'currency', 'is_active', 'description'];

    public function __construct(private PmsPropertyAuthority $authority, private PmsServiceInput $input) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.view');

        return RatePlan::query()->where('property_id', $propertyId)
            ->when($filters['plan_type'] ?? null, fn ($q, $v) => $q->where('plan_type', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->latest()->paginate(min(max($perPage, 1), 100));
    }

    public function find(string $id): RatePlan
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.view');

        return RatePlan::query()->where('property_id', $propertyId)->findOrFail($id);
    }

    public function create(array $data): RatePlan
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.manage');
        $data = $this->input->ratePlan($this->allowed($data), true, $propertyId);
        foreach (['currency', 'is_active'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                unset($data[$field]);
            }
        }

        return RatePlan::create($data + ['property_id' => $propertyId])->fresh();
    }

    public function update(string $id, array $data): RatePlan
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.manage');
        $model = RatePlan::query()->where('property_id', $propertyId)->findOrFail($id);
        $model->update($this->input->ratePlan($this->allowed($data), false, $propertyId, $model->id));

        return $model->fresh();
    }

    public function delete(string $id): bool
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.manage');

        return RatePlan::query()->where('property_id', $propertyId)->findOrFail($id)->delete();
    }

    public function active(): Collection
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.view');

        return RatePlan::query()->where('property_id', $propertyId)->where('is_active', true)->get();
    }

    public function findByCode(string $code): ?RatePlan
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.view');

        return RatePlan::query()->where('property_id', $propertyId)->where('rate_code', $code)->first();
    }

    private function allowed(array $data): array
    {
        if (array_diff(array_keys($data), self::WRITABLE) !== []) {
            throw ValidationException::withMessages(['rate_plan' => ['Unsupported rate plan fields.']]);
        }

        return $data;
    }
}
