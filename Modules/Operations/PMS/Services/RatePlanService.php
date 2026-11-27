<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        $data = $this->allowed($data);

        return DB::transaction(function () use ($propertyId, $data): RatePlan {
            $this->lockProperty($propertyId);
            $data = $this->input->ratePlan($data, true, $propertyId);
            foreach (['currency', 'is_active'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] === null) {
                    unset($data[$field]);
                }
            }

            return RatePlan::create($data + ['property_id' => $propertyId])->fresh();
        });
    }

    public function update(string $id, array $data): RatePlan
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.manage');
        $data = $this->allowed($data);

        return DB::transaction(function () use ($id, $propertyId, $data): RatePlan {
            $this->lockProperty($propertyId);
            $model = RatePlan::query()->where('property_id', $propertyId)->lockForUpdate()->findOrFail($id);
            $model->update($this->input->ratePlan($data, false, $propertyId, $model->id));

            return $model->fresh();
        });
    }

    public function delete(string $id): bool
    {
        [, $propertyId] = $this->authority->require('pms.rate-plan.manage');

        return DB::transaction(function () use ($id, $propertyId): bool {
            $this->lockProperty($propertyId);
            $model = RatePlan::query()->where('property_id', $propertyId)->lockForUpdate()->findOrFail($id);
            if (DB::table('reservations')->where('rate_plan_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'rate_plan_id' => ['A rate plan referenced by a reservation cannot be deleted.'],
                ]);
            }

            return $model->delete();
        });
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

    private function lockProperty(string $propertyId): void
    {
        abort_unless(DB::table('properties')->where('id', $propertyId)->lockForUpdate()->first(), 403);
    }
}
