<?php

namespace Modules\Foundation\Property\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Throwable;

class PropertyBusinessDateInitializationService
{
    public const ERROR_INVALID_TIMEZONE = 'BD_A1_INVALID_PROPERTY_TIMEZONE';
    public const ERROR_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY = 'BD_A1_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY';
    public const ERROR_MULTIPLE_OPEN = 'BD_A1_MULTIPLE_OPEN_BUSINESS_DATES';

    public function __construct(
        private readonly PropertyBusinessDateAuthorizationService $authorization,
    ) {}

    public function initialize(User $actor): PropertyBusinessDate
    {
        $this->authorization->authorizeInitialization($actor);

        return DB::transaction(function () use ($actor): PropertyBusinessDate {
            $authorizedProperty = $this->authorization->authorizeInitialization($actor);

            $property = Property::withoutGlobalScopes()
                ->whereKey($authorizedProperty->id)
                ->lockForUpdate()
                ->first();

            if (! $property) {
                throw new AuthorizationException(PropertyBusinessDateAuthorizationService::FAILURE_MESSAGE);
            }

            $this->revalidateLockedContext($actor, $property);

            $timezone = $this->validatedTimezone((string) $property->timezone);
            $openedAt = CarbonImmutable::now('UTC');
            $businessDate = $openedAt->setTimezone($timezone)->toDateString();

            $history = PropertyBusinessDate::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->lockForUpdate()
                ->orderBy('business_date')
                ->orderBy('id')
                ->get();

            $openRows = $history
                ->filter(fn (PropertyBusinessDate $row): bool => $row->status === PropertyBusinessDateStatusEnum::Open && $row->is_open === true)
                ->values();

            if ($openRows->count() === 1) {
                return $openRows->first();
            }

            if ($openRows->count() > 1) {
                throw new RuntimeException(self::ERROR_MULTIPLE_OPEN);
            }

            if ($history->isNotEmpty()) {
                throw new RuntimeException(self::ERROR_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY);
            }

            $row = new PropertyBusinessDate();
            $row->forceFill([
                'property_id' => $property->id,
                'business_date' => $businessDate,
                'timezone_snapshot' => $timezone,
                'status' => PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_by' => $actor->id,
                'opened_at' => $openedAt,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $row->save();

            return $row->fresh();
        }, 1);
    }

    private function revalidateLockedContext(User $actor, Property $property): void
    {
        if (! auth()->check() || (string) auth()->id() !== (string) $actor->id) {
            throw new AuthorizationException(PropertyBusinessDateAuthorizationService::FAILURE_MESSAGE);
        }

        $fresh = User::whereKey($actor->id)
            ->where('is_active', true)
            ->first();

        $companyId = session('active_company_id');
        $company = is_string($companyId) && trim($companyId) !== ''
            ? Company::withoutGlobalScopes()->whereKey($companyId)->where('is_active', true)->first()
            : null;

        if (! $fresh || ! $company || ! $property->is_active || $property->company_id !== $company->id) {
            throw new AuthorizationException(PropertyBusinessDateAuthorizationService::FAILURE_MESSAGE);
        }

        $hasMembership = $fresh->properties()
            ->where('properties.id', $property->id)
            ->wherePivot('status', 'active')
            ->exists();

        try {
            $allowed = $fresh->can(PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION);
        } catch (Throwable) {
            $allowed = false;
        }

        if (! $hasMembership || ! $allowed) {
            throw new AuthorizationException(PropertyBusinessDateAuthorizationService::FAILURE_MESSAGE);
        }
    }

    private function validatedTimezone(string $timezone): string
    {
        $timezone = trim($timezone);

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException(self::ERROR_INVALID_TIMEZONE);
        }

        return $timezone;
    }
}
