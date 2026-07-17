<?php

namespace Modules\Operations\NightAudit\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class NightAuditAuthorizationService
{
    public const VIEW_PERMISSION = 'night-audit.current.view';
    public const START_PERMISSION = 'night-audit.run.start';
    public const ABORT_PERMISSION = 'night-audit.run.abort';
    public const FAILURE_MESSAGE = 'Night Audit access is not authorized.';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    public function authorizeView(User $actor): Property
    {
        return $this->authorize($actor, self::VIEW_PERMISSION);
    }

    public function authorizeStart(User $actor): Property
    {
        return $this->authorize($actor, self::START_PERMISSION);
    }

    public function authorizeAbort(User $actor): Property
    {
        return $this->authorize($actor, self::ABORT_PERMISSION);
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

        $property = Property::withoutGlobalScopes()
            ->whereKey(trim($propertyId))
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

        if (! $this->actorCan($fresh, $permission)
            || ! $this->actorCan($fresh, PropertyBusinessDateAuthorizationService::VIEW_PERMISSION)) {
            $this->deny();
        }

        return $property;
    }

    private function actorCan(User $actor, string $permission): bool
    {
        try {
            return $actor->can($permission);
        } catch (Throwable) {
            return false;
        }
    }

    private function deny(): never
    {
        throw new AuthorizationException(self::FAILURE_MESSAGE);
    }
}
