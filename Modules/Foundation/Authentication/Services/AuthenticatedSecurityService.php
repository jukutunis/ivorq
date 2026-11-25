<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class AuthenticatedSecurityService
{
    public function __construct(private readonly CurrentPropertyService $currentProperty) {}

    public function valid(User $user, Request $request, mixed $token = null): bool
    {
        $fresh = User::query()->whereKey($user->id)->first();
        if (! $fresh || ! $fresh->is_active) {
            return false;
        }

        $activation = OwnerActivation::query()->where('user_id', $fresh->id)->first();

        if ($token instanceof PersonalAccessToken) {
            $propertyId = $token->property_id;
            if (! is_string($propertyId) || $propertyId === ''
                || (int) $token->auth_epoch !== (int) $fresh->auth_epoch
                || ($token->expires_at !== null && $token->expires_at->isPast())) {
                return false;
            }
            $explicitPropertyId = $this->currentProperty->getExplicitPropertyId();
            if ($explicitPropertyId !== null && $explicitPropertyId !== $propertyId) {
                return false;
            }
            if (! DB::table('user_sessions')->where('user_id', $fresh->id)->where('token_id', $token->id)
                ->where('auth_epoch', $fresh->auth_epoch)->where('property_id', $propertyId)->exists()) {
                return false;
            }

            if (! $this->validCommonPropertyBinding($fresh, $propertyId)) {
                return false;
            }
        } else {
            if (! $request->hasSession()
                || (int) $request->session()->get('auth_epoch', -1) !== (int) $fresh->auth_epoch
                || ! is_string($request->session()->get('current_property_id'))
                || ! is_string($request->session()->get('active_company_id'))) {
                return false;
            }

            $propertyId = (string) $request->session()->get('current_property_id');
            $compatibilityPropertyId = $request->session()->get('active_property_id');
            $explicitPropertyId = $this->currentProperty->getExplicitPropertyId();
            if (($compatibilityPropertyId !== null && $compatibilityPropertyId !== $propertyId)
                || ($explicitPropertyId !== null && $explicitPropertyId !== $propertyId)) {
                return false;
            }

            $digest = hash('sha256', "IVORQ-WEB-SESSION-V1\0".$request->session()->getId());
            if (! DB::table('user_sessions')->where('user_id', $fresh->id)->where('web_session_digest', $digest)
                ->where('auth_epoch', $fresh->auth_epoch)->where('property_id', $propertyId)->exists()) {
                return false;
            }

            if (! $this->validCommonPropertyBinding($fresh, $propertyId, (string) $request->session()->get('active_company_id'))) {
                return false;
            }
        }

        $this->currentProperty->setPropertyId($propertyId);

        return ! $activation || $this->validOwnerBinding($fresh, $activation, $propertyId);
    }

    private function validCommonPropertyBinding(User $user, string $propertyId, ?string $companyId = null): bool
    {
        return DB::table('property_user')
            ->join('properties', 'properties.id', '=', 'property_user.property_id')
            ->join('companies', 'companies.id', '=', 'properties.company_id')
            ->where('property_user.user_id', $user->id)
            ->where('property_user.property_id', $propertyId)
            ->when($companyId !== null, fn ($query) => $query->where('properties.company_id', $companyId))
            ->where('property_user.status', 'active')
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->where('companies.is_active', true)
            ->whereNull('companies.deleted_at')
            ->exists();
    }

    private function validOwnerBinding(User $user, OwnerActivation $activation, string $propertyId): bool
    {
        if ($activation->status !== OwnerActivationStatus::Active
            || (string) $activation->property_id !== $propertyId) {
            return false;
        }

        $membership = DB::table('property_user')
            ->join('properties', 'properties.id', '=', 'property_user.property_id')
            ->where('property_user.user_id', $user->id)
            ->where('property_user.property_id', $propertyId)
            ->where('properties.company_id', $activation->company_id)
            ->where('property_user.status', 'active')
            ->where('property_user.is_default', true)
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->exists();
        $role = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.property_id', $propertyId)
            ->where('roles.property_id', $propertyId)
            ->where('roles.name', 'installation-owner')
            ->exists();

        return $membership && $role;
    }
}
