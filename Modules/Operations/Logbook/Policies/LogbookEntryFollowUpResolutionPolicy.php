<?php

namespace Modules\Operations\Logbook\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Logbook\Models\LogbookEntry;
use Modules\Operations\Logbook\Enums\LogbookEntryStatusEnum;
use Shared\Services\CurrentPropertyService;

class LogbookEntryFollowUpResolutionPolicy
{
    public function resolve(User $user, LogbookEntry $entry): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        return $entry->status === LogbookEntryStatusEnum::Submitted
            && $entry->requires_follow_up
            && $entry->created_by === $user->id
            && ($user->isSuperAdmin() || ($entry->property_id === $propertyId && $user->properties()->where('properties.id', $propertyId)->exists()));
    }
}
