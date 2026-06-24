<?php

namespace Modules\Operations\Logbook\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Logbook\Models\ShiftLog;
use Modules\Operations\Logbook\Enums\ShiftLogStatusEnum;
use Shared\Services\CurrentPropertyService;

class ShiftLogPolicy
{
    public function viewAny(User $user): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists();
    }

    public function view(User $user, ShiftLog $log): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return ($user->isSuperAdmin() || ($log->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function create(User $user): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists();
    }

    public function update(User $user, ShiftLog $log): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $log->status === ShiftLogStatusEnum::Draft
            && $log->created_by === $user->id
            && ($user->isSuperAdmin() || ($log->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function submit(User $user, ShiftLog $log): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $log->status === ShiftLogStatusEnum::Draft
            && $log->created_by === $user->id
            && ($user->isSuperAdmin() || ($log->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function acknowledge(User $user, ShiftLog $log): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $log->status === ShiftLogStatusEnum::Submitted
            && $log->created_by !== $user->id
            && ($user->isSuperAdmin() || ($log->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }
}
