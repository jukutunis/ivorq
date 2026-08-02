<?php

namespace Modules\Operations\Housekeeping\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\RoomInspection;

class RoomInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.inspection.view')
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    public function view(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.inspection.view')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function create(User $user): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    public function update(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        if (in_array($inspection->status, [
            \Modules\Operations\Housekeeping\Enums\InspectionStatusEnum::Passed,
            \Modules\Operations\Housekeeping\Enums\InspectionStatusEnum::Failed,
        ], true)) {
            return false;
        }
        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function delete(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        $type = $inspection->inspection_type instanceof \BackedEnum
            ? $inspection->inspection_type->value
            : (string) $inspection->inspection_type;
        if (
            in_array($inspection->status, [
                \Modules\Operations\Housekeeping\Enums\InspectionStatusEnum::Passed,
                \Modules\Operations\Housekeeping\Enums\InspectionStatusEnum::Failed,
            ], true)
            || $type === 'post_cleaning'
        ) {
            return false;
        }
        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function conduct(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        return $user->hasPermissionTo('housekeeping.inspection.conduct')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }
}
