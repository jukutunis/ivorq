<?php

namespace Modules\Operations\Logbook\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Shared\Services\CurrentPropertyService;

class LogbookEntryPolicy
{
    public function viewAny(User $user): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists();
    }

    public function view(User $user, LogbookEntry $entry): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return ($user->isSuperAdmin() || ($entry->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function create(User $user): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists();
    }

    public function update(User $user, LogbookEntry $entry): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $entry->status === LogbookEntryStatusEnum::Draft
            && $entry->created_by === $user->id
            && ($user->isSuperAdmin() || ($entry->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }

    public function submit(User $user, LogbookEntry $entry): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $entry->status === LogbookEntryStatusEnum::Draft
            && $entry->created_by === $user->id
            && ($user->isSuperAdmin() || ($entry->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }
}
