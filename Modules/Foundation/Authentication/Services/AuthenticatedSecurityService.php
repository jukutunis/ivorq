<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\User\Models\User;

class AuthenticatedSecurityService
{
    public function valid(User $user, Request $request, mixed $token = null): bool
    {
        $fresh = User::query()->whereKey($user->id)->first();
        if (! $fresh || ! $fresh->is_active) {
            return false;
        }

        $activation = OwnerActivation::query()->where('user_id', $fresh->id)->first();

        // B5A1B auth-epoch and property binding are deliberately scoped to the
        // installation-owner runtime. Existing non-owner Sanctum credentials
        // remain backward compatible while still inheriting the active-user
        // check above.
        if (! $activation) {
            return true;
        }

        if ($token instanceof PersonalAccessToken) {
            if ((int) $token->auth_epoch !== (int) $fresh->auth_epoch) {
                return false;
            }
            if (! DB::table('user_sessions')->where('user_id', $fresh->id)->where('token_id', $token->id)
                ->where('auth_epoch', $fresh->auth_epoch)->where('property_id', $token->property_id)->exists()) {
                return false;
            }

            return $this->validOwnerBinding($fresh, $activation, (string) $token->property_id);
        }

        if (! $request->hasSession()
            || (int) $request->session()->get('auth_epoch', -1) !== (int) $fresh->auth_epoch
            || ! is_string($request->session()->get('active_property_id'))) {
            return false;
        }
        $propertyId = (string) $request->session()->get('active_property_id');
        $digest = hash('sha256', "IVORQ-WEB-SESSION-V1\0".$request->session()->getId());
        if (! DB::table('user_sessions')->where('user_id', $fresh->id)->where('web_session_digest', $digest)
            ->where('auth_epoch', $fresh->auth_epoch)->where('property_id', $propertyId)->exists()) {
            return false;
        }

        return $this->validOwnerBinding($fresh, $activation, $propertyId);
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
