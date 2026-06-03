<?php

namespace Modules\Foundation\Activity\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Activity\Models\ActivityLog;

class ActivityService
{
    public function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = [],
        ?string $propertyId = null
    ): ActivityLog {
        $resolvedPropertyId = $propertyId
            ?? ($subject && isset($subject->property_id) ? $subject->property_id : null)
            ?? app(\Shared\Services\CurrentPropertyService::class)->getId();

        $resolvedCauser = $causer ?? (auth()->check() ? auth()->user() : null);

        return ActivityLog::record([
            'property_id'  => $resolvedPropertyId,
            'user_id'      => auth()->id(),
            'description'  => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'causer_type'  => $resolvedCauser ? get_class($resolvedCauser) : null,
            'causer_id'    => $resolvedCauser?->getKey(),
            'properties'   => $properties,
        ]);
    }
}
