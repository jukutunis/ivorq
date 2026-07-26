<?php

namespace Modules\Operations\FrontDesk\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class FrontDeskCheckoutExecuteAuthorizationService
{
    public const EXECUTE_PERMISSION = 'frontdesk.checkout-execution.execute';
    public const ERROR_EXECUTE_PERMISSION_MISSING = 'P8_CHECKOUT_EXECUTE_PERMISSION_MISSING';
    public const ERROR_INVALID_AUTHENTICATED_CONTEXT = 'P8_CHECKOUT_INVALID_AUTHENTICATED_CONTEXT';
    public const ERROR_UNAUTHORIZED_PROPERTY_CONTEXT = 'P8_CHECKOUT_UNAUTHORIZED_PROPERTY_CONTEXT';
    public const ERROR_STAY_NOT_FOUND = 'P8_CHECKOUT_STAY_NOT_FOUND';

    public function __construct(private readonly CurrentPropertyService $currentProperty) {}

    public function resolveAuthorizedStay(User $actor, string $frontDeskStayId): FrontDeskStay
    {
        $context = $this->resolveAuthorizedContext($actor, $frontDeskStayId);

        return $context['stay'];
    }

    /**
     * @return array{actor: User, company: Company, property: Property, stay: FrontDeskStay}
     */
    public function resolveAuthorizedContext(User $actor, string $frontDeskStayId): array
    {
        $context = $this->authorize($actor);

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($frontDeskStayId)
            ->where('property_id', $context['property']->id)
            ->first();

        if (! $stay) {
            throw new HttpException(404, self::ERROR_STAY_NOT_FOUND);
        }

        return [
            'actor' => $context['actor'],
            'company' => $context['company'],
            'property' => $context['property'],
            'stay' => $stay,
        ];
    }

    /**
     * @return array{actor: User, company: Company, property: Property}
     */
    public function authorize(User $actor): array
    {
        if (! auth()->check() || auth()->id() !== $actor->id) {
            throw new AuthorizationException(self::ERROR_INVALID_AUTHENTICATED_CONTEXT);
        }

        $fresh = User::whereKey($actor->id)
            ->where('is_active', true)
            ->first();

        if (! $fresh) {
            throw new AuthorizationException(self::ERROR_INVALID_AUTHENTICATED_CONTEXT);
        }

        $companyId = session('active_company_id');
        if (! is_string($companyId) || trim($companyId) === '') {
            throw new AuthorizationException(self::ERROR_INVALID_AUTHENTICATED_CONTEXT);
        }

        $company = Company::withoutGlobalScopes()
            ->whereKey($companyId)
            ->where('is_active', true)
            ->first();

        if (! $company) {
            throw new AuthorizationException(self::ERROR_INVALID_AUTHENTICATED_CONTEXT);
        }

        $propertyId = $this->currentProperty->getPropertyId();
        if (! is_string($propertyId) || trim($propertyId) === '') {
            throw new AuthorizationException(self::ERROR_UNAUTHORIZED_PROPERTY_CONTEXT);
        }

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();

        if (! $property) {
            throw new AuthorizationException(self::ERROR_UNAUTHORIZED_PROPERTY_CONTEXT);
        }

        $hasMembership = $fresh->properties()
            ->where('properties.id', $property->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $hasMembership) {
            throw new AuthorizationException(self::ERROR_UNAUTHORIZED_PROPERTY_CONTEXT);
        }

        if (! $this->actorCan($fresh, self::EXECUTE_PERMISSION)) {
            throw new AuthorizationException(self::ERROR_EXECUTE_PERMISSION_MISSING);
        }

        return ['actor' => $fresh, 'company' => $company, 'property' => $property];
    }

    private function actorCan(User $actor, string $permission): bool
    {
        try {
            return $actor->can($permission);
        } catch (Throwable) {
            return false;
        }
    }
}
