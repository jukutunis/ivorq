<?php

namespace Modules\Finance\GeneralLedger\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class FinancialPeriodAuthorizationService
{
    public const INITIALIZE_PERMISSION = 'generalledger.period.manage';

    public const FAILURE_MESSAGE = 'Financial Period initialization is not authorized.';

    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
    ) {}

    public function authorizeInitialization(User $actor): Property
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

        try {
            $propertyId = trim($this->currentProperty->resolveOrFail());
        } catch (Throwable) {
            $this->deny();
        }

        if ($propertyId === '') {
            $this->deny();
        }

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
            $allowed = $fresh->can(self::INITIALIZE_PERMISSION);
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
