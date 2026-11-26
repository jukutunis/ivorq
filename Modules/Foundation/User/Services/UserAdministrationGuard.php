<?php

namespace Modules\Foundation\User\Services;

use DomainException;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;

class UserAdministrationGuard
{
    public function assertOrdinaryMutationAllowed(User $target, Property $currentProperty): void
    {
        if (OwnerActivation::query()->where('user_id', $target->id)->exists()) {
            throw new DomainException('INSTALLATION_OWNER_REQUIRES_HIGH_ASSURANCE_IDENTITY_GOVERNANCE');
        }

        $crossCompanyMembership = $target->properties()
            ->where('properties.company_id', '!=', $currentProperty->company_id)
            ->exists();

        if ($crossCompanyMembership) {
            throw new DomainException('MULTI_COMPANY_IDENTITY_REQUIRES_GLOBAL_GOVERNANCE');
        }
    }
}
