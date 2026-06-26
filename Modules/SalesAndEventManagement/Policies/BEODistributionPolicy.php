<?php

namespace Modules\SalesAndEventManagement\Policies;

use Modules\Foundation\User\Models\User;
use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Shared\Services\CurrentPropertyService;

/**
 * BEODistributionPolicy
 *
 * Enforces property isolation and role-based access for BEO Distribution
 * actions. Mirrors the guard pattern established in ShiftLogPolicy.
 *
 * All checks trust only server-resolved property context
 * (CurrentPropertyService), never client-supplied identifiers.
 */
class BEODistributionPolicy
{
    /**
     * Distribute a DRAFT BEO to departments.
     * Requires the user to belong to the resolved property.
     */
    public function distribute(User $user, BEODistribution $distribution): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $distribution->property_id === $propertyId
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists())
            && $distribution->status === DistributionStatusEnum::DRAFT;
    }

    /**
     * Cancel a distribution from any non-terminal state.
     * Property and membership check identical to distribute.
     */
    public function cancel(User $user, BEODistribution $distribution): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        $terminal = [
            DistributionStatusEnum::COMPLETED,
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
        ];

        return $distribution->property_id === $propertyId
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists())
            && ! in_array($distribution->status, $terminal, true);
    }

    /**
     * Acknowledge an assigned BEO acknowledgement.
     * Property and membership check identical.
     */
    public function acknowledge(User $user, BEOAcknowledgement $acknowledgement): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $acknowledgement->distribution->property_id === $propertyId
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }

    /**
     * Reject an assigned BEO acknowledgement.
     * Property and membership check identical.
     */
    public function reject(User $user, BEOAcknowledgement $acknowledgement): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();

        return $acknowledgement->distribution->property_id === $propertyId
            && ($user->isSuperAdmin() || $user->properties()->where('properties.id', $propertyId)->exists());
    }
}
