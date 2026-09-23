<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\IdentityChallenge;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\ValueObjects\VerifiedMfaAuthentication;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;

class TokenService
{
    public function createForPasswordAuthentication(User $user, string $propertyId, string $deviceName = 'api'): string
    {
        if (OwnerActivation::query()->where('user_id', $user->id)->where('status', OwnerActivationStatus::Active->value)->exists()) {
            throw ValidationException::withMessages(['mfa' => ['Multi-factor authentication is required.']]);
        }

        return $this->issue($user, $propertyId, $deviceName, null);
    }

    public function createForVerifiedOwner(VerifiedMfaAuthentication $authentication, string $deviceName = 'api'): string
    {
        return DB::transaction(function () use ($authentication, $deviceName): string {
            $user = $this->claimVerifiedOwner($authentication);

            return $this->issue($user, $authentication->propertyId, $deviceName, now()->addHours(8));
        });
    }

    public function recordWebSession(User $user, string $propertyId, string $sessionId, string $deviceName = 'web'): void
    {
        if (OwnerActivation::query()->where('user_id', $user->id)->where('status', OwnerActivationStatus::Active->value)->exists()) {
            throw ValidationException::withMessages(['mfa' => ['Multi-factor authentication is required.']]);
        }

        $this->recordWebSessionRow($user, $propertyId, $sessionId, $deviceName);
    }

    public function recordVerifiedOwnerWebSession(VerifiedMfaAuthentication $authentication, string $sessionId, string $deviceName = 'web'): User
    {
        return DB::transaction(function () use ($authentication, $sessionId, $deviceName): User {
            $user = $this->claimVerifiedOwner($authentication);
            $this->recordWebSessionRow($user, $authentication->propertyId, $sessionId, $deviceName);

            return $user;
        });
    }

    private function recordWebSessionRow(User $user, string $propertyId, string $sessionId, string $deviceName): void
    {
        UserSession::query()->create([
            'user_id' => $user->id,
            'property_id' => $propertyId,
            'token_id' => null,
            'web_session_digest' => hash('sha256', "IVORQ-WEB-SESSION-V1\0".$sessionId),
            'auth_epoch' => $user->auth_epoch,
            'channel' => 'web',
            'device_name' => $deviceName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'last_active_at' => now(),
        ]);
    }

    private function claimVerifiedOwner(VerifiedMfaAuthentication $authentication): User
    {
        $challenge = IdentityChallenge::query()
            ->whereKey($authentication->challengeId)
            ->where('purpose', 'LOGIN_MFA')
            ->where('user_id', $authentication->userId)
            ->where('company_id', $authentication->companyId)
            ->where('property_id', $authentication->propertyId)
            ->whereNotNull('consumed_at')
            ->whereNotNull('password_verified_at')
            ->whereNull('credential_issued_at')
            ->lockForUpdate()
            ->first();
        $user = User::query()->whereKey($authentication->userId)->where('is_active', true)->lockForUpdate()->first();
        $activation = OwnerActivation::query()
            ->where('user_id', $authentication->userId)
            ->where('company_id', $authentication->companyId)
            ->where('property_id', $authentication->propertyId)
            ->where('status', OwnerActivationStatus::Active->value)
            ->lockForUpdate()
            ->first();
        $membership = DB::table('property_user')
            ->join('properties', 'properties.id', '=', 'property_user.property_id')
            ->where('property_user.user_id', $authentication->userId)
            ->where('property_user.property_id', $authentication->propertyId)
            ->where('properties.company_id', $authentication->companyId)
            ->where('property_user.status', 'active')
            ->where('property_user.is_default', true)
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->exists();
        $role = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $authentication->userId)
            ->where('model_has_roles.property_id', $authentication->propertyId)
            ->where('roles.property_id', $authentication->propertyId)
            ->where('roles.name', 'installation-owner')
            ->exists();

        if (! $challenge || ! $user || ! $activation || ! $membership || ! $role) {
            throw ValidationException::withMessages(['mfa' => ['Multi-factor authentication verification is invalid.']]);
        }

        $challenge->forceFill(['credential_issued_at' => now()])->save();

        return $user;
    }

    private function issue(User $user, string $propertyId, string $deviceName, mixed $expiresAt): string
    {
        $token = $user->createToken($deviceName, ['*'], $expiresAt);
        $token->accessToken->forceFill([
            'auth_epoch' => $user->auth_epoch,
            'property_id' => $propertyId,
        ])->save();

        $this->recordSession($user, $token, $propertyId, $deviceName);

        return $token->plainTextToken;
    }

    public function revoke(User $user, int $tokenId): bool
    {
        return $user->tokens()->where('id', $tokenId)->delete() > 0;
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    private function recordSession(User $user, NewAccessToken $token, string $propertyId, string $deviceName): void
    {
        UserSession::create([
            'user_id' => $user->id,
            'property_id' => $propertyId,
            'token_id' => $token->accessToken->id,
            'auth_epoch' => $user->auth_epoch,
            'channel' => 'api',
            'device_name' => $deviceName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'last_active_at' => now(),
        ]);
    }
}
