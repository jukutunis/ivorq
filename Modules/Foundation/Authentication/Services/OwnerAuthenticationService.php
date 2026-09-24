<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Models\OwnerRecoveryCode;
use Modules\Foundation\Authentication\ValueObjects\VerifiedMfaAuthentication;
use Modules\Foundation\User\Models\User;
use SensitiveParameter;

class OwnerAuthenticationService
{
    public function __construct(
        private IdentityChallengeService $challenges,
        private OwnerTotpService $totp,
        private OwnerRecoveryCodeService $recoveryCodes,
        private IdentitySecurityEventService $events,
        private SessionRevocationService $revocation,
    ) {}

    public function completeTotp(#[SensitiveParameter] string $bearer, #[SensitiveParameter] string $code, string $channel, ?string $guestSessionId): VerifiedMfaAuthentication
    {
        return $this->complete($bearer, $channel, $guestSessionId, 'totp', fn (string $userId) => $this->totp->verifyActive($userId, $code));
    }

    public function completeRecoveryCode(#[SensitiveParameter] string $bearer, #[SensitiveParameter] string $code, string $channel, ?string $guestSessionId): VerifiedMfaAuthentication
    {
        return $this->complete($bearer, $channel, $guestSessionId, 'recovery_code', function (string $userId) use ($code): void {
            $this->recoveryCodes->claim($userId, $code);
            $this->events->record('RECOVERY_CODE_USED', 'SUCCESS', ['subject_user_id' => $userId]);
        });
    }

    public function regenerateRecoveryCodes(User $actor, #[SensitiveParameter] string $password, #[SensitiveParameter] string $totpCode): array
    {
        return DB::transaction(function () use ($actor, $password, $totpCode): array {
            $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($user->password === null || ! Hash::check($password, $user->password)) {
                throw $this->generic();
            }

            // ACTIVE is terminal and identity fields are immutable. Reading it
            // after the User lock avoids reversing the activation flow's older
            // challenge/activation/User lock order while keeping this mutation
            // serialized by the authoritative User row.
            $activation = OwnerActivation::query()->where('user_id', $user->id)->where('status', OwnerActivationStatus::Active->value)->firstOrFail();
            $factor = $this->totp->verifyActive($user->id, $totpCode);
            $codes = $this->recoveryCodes->regenerate($factor);
            $generation = (int) OwnerRecoveryCode::query()->where('factor_id', $factor->id)->max('generation');
            $this->events->record('RECOVERY_CODES_REGENERATED', 'SUCCESS', $this->context($activation, $user), [
                'recovery_generation' => $generation,
            ]);
            $this->revocation->revokeAll($user, 'RECOVERY_CODES_REGENERATED', $this->context($activation, $user));

            return $codes;
        });
    }

    private function complete(#[SensitiveParameter] string $bearer, string $channel, ?string $guestSessionId, string $mfaMethod, callable $factorVerifier): VerifiedMfaAuthentication
    {
        $failure = null;
        $result = DB::transaction(function () use ($bearer, $channel, $guestSessionId, $mfaMethod, $factorVerifier, &$failure): ?VerifiedMfaAuthentication {
            $challenge = $this->challenges->lockValid($bearer, 'LOGIN_MFA');
            if ($challenge->channel !== $channel) {
                $failure = $this->generic();
                $this->recordFailure($challenge, 'CHANNEL_MISMATCH', $mfaMethod);

                return null;
            }
            if ($channel === 'web') {
                $expected = $guestSessionId === null ? '' : hash('sha256', "IVORQ-GUEST-SESSION-V1\0".$guestSessionId);
                if ($challenge->guest_session_digest === null || ! hash_equals($challenge->guest_session_digest, $expected)) {
                    $failure = $this->generic();
                    $this->recordFailure($challenge, 'GUEST_SESSION_MISMATCH', $mfaMethod);

                    return null;
                }
            }

            $user = User::query()->whereKey($challenge->user_id)->lockForUpdate()->first();
            $activation = OwnerActivation::query()->whereKey($challenge->activation_id)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || ! $activation || $activation->status !== OwnerActivationStatus::Active
                || (string) $activation->company_id !== (string) $challenge->company_id
                || (string) $activation->property_id !== (string) $challenge->property_id
                || ! $this->hasBinding($user, $activation)) {
                $failure = $this->generic();
                $this->recordFailure($challenge, 'AUTHORITY_BINDING_INVALID', $mfaMethod);

                return null;
            }

            try {
                $factorVerifier($user->id);
            } catch (ValidationException|ModelNotFoundException) {
                $failure = $this->generic();
                $this->recordFailure($challenge, 'SECOND_FACTOR_INVALID', $mfaMethod);

                return null;
            }

            $this->challenges->consume($challenge);
            $this->events->record('LOGIN_MFA_SUCCEEDED', 'SUCCESS', $this->context($activation, $user, $challenge->id), [
                'mfa_method' => $mfaMethod,
            ]);

            return new VerifiedMfaAuthentication($user->id, $activation->company_id, $activation->property_id, $challenge->id);
        });

        if ($failure) {
            throw $failure;
        }

        return $result;
    }

    private function hasBinding(User $user, OwnerActivation $activation): bool
    {
        $membership = DB::table('property_user')
            ->join('properties', 'properties.id', '=', 'property_user.property_id')
            ->where('property_user.user_id', $user->id)
            ->where('property_user.property_id', $activation->property_id)
            ->where('properties.company_id', $activation->company_id)
            ->where('property_user.status', 'active')
            ->where('property_user.is_default', true)
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->lockForUpdate()
            ->exists();
        $role = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.property_id', $activation->property_id)
            ->where('roles.property_id', $activation->property_id)
            ->where('roles.name', 'installation-owner')
            ->exists();

        return $membership && $role;
    }

    private function recordFailure($challenge, string $reason, string $mfaMethod): void
    {
        $this->challenges->fail($challenge);
        $this->events->record('LOGIN_MFA_FAILED', 'FAILURE', [
            'subject_user_id' => $challenge->user_id,
            'company_id' => $challenge->company_id,
            'property_id' => $challenge->property_id,
            'activation_id' => $challenge->activation_id,
            'challenge_id' => $challenge->id,
            'reason_code' => $reason,
        ], [
            'mfa_method' => $mfaMethod,
            'attempts_remaining' => max(0, (int) $challenge->max_attempts - (int) $challenge->failed_attempts),
        ]);
    }

    private function context(OwnerActivation $activation, User $user, ?string $challengeId = null): array
    {
        $context = [
            'actor_user_id' => $user->id,
            'subject_user_id' => $user->id,
            'company_id' => $activation->company_id,
            'property_id' => $activation->property_id,
            'activation_id' => $activation->id,
        ];

        if ($challengeId !== null) {
            $context['challenge_id'] = $challengeId;
        }

        return $context;
    }

    private function generic(): ValidationException
    {
        return ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
    }
}
