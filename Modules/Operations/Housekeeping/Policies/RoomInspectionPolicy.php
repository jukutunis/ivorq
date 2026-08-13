<?php

namespace Modules\Operations\Housekeeping\Policies;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Services\CurrentPropertyService;

class RoomInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $user->hasPermissionTo('housekeeping.inspection.view')
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    public function view(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $user->hasPermissionTo('housekeeping.inspection.view')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function create(User $user): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    public function update(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        if (in_array($inspection->status, [
            InspectionStatusEnum::Passed,
            InspectionStatusEnum::Failed,
        ], true)) {
            return false;
        }

        return $user->hasPermissionTo('housekeeping.inspection.create')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function delete(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        $type = $inspection->inspection_type instanceof \BackedEnum
            ? $inspection->inspection_type->value
            : (string) $inspection->inspection_type;
        if (
            in_array($inspection->status, [
                InspectionStatusEnum::Passed,
                InspectionStatusEnum::Failed,
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
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $user->hasPermissionTo('housekeeping.inspection.conduct')
            && ($user->isSuperAdmin() || ($propertyId === $inspection->property_id && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function reassignClaim(User $user, RoomInspection $inspection): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $propertyId === $inspection->property_id
            && $user->is_active
            && $user->deleted_at === null
            && $user->hasPermissionTo('housekeeping.inspection.approve')
            && DB::table('property_user')
                ->where('property_id', $propertyId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
    }
}
