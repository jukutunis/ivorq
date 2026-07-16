<?php

namespace Modules\Foundation\Property\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class PropertyBusinessDateAuthorizationService
{
    public const VIEW_PERMISSION = 'business-date.current.view';
    public const INITIALIZE_PERMISSION = 'business-date.initialize';
    public const FAILURE_MESSAGE = 'Property Business Date access is not authorized.';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    public function authorizeView(User $actor): Property
    {
        return $this->authorize($actor, self::VIEW_PERMISSION);
    }

    public function authorizeInitialization(User $actor): Property
    {
        return $this->authorize($actor, self::INITIALIZE_PERMISSION);
    }

    private function authorize(User $actor, string $permission): Property
    {
        if (! auth()->check() || (string) auth()->id() !== (string) $actor->id) {
            $this->deny();
        }

        $fresh = User::whereKey($actor->id)
            ->where('is_active', true)
            ->first();

        if (! $fresh) {
            $this->deny();
        }

        $companyId = session('active_company_id');
        if (! is_string($companyId) || trim($companyId) === '') {
            $this->deny();
        }

        $company = Company::withoutGlobalScopes()
            ->whereKey($companyId)
            ->where('is_active', true)
            ->first();

        if (! $company) {
            $this->deny();
        }

        $propertyId = $this->currentProperty->getPropertyId();
        if (! is_string($propertyId) || trim($propertyId) === '') {
            $this->deny();
        }
        $propertyId = trim($propertyId);

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->where('company_id', $company->id)
            ->first();

        if (! $property) {
            $this->deny();
        }

        $hasMembership = $fresh->properties()
            ->where('properties.id', $property->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasMembership) {
            $this->deny();
        }

        try {
            $allowed = $fresh->can($permission);
        } catch (Throwable) {
            $allowed = false;
        }

        if (! $allowed) {
            $this->deny();
        }

        return $property;
    }

    private function deny(): never
    {
        throw new AuthorizationException(self::FAILURE_MESSAGE);
    }
}
