<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Rules\PrivilegedOwnerPassword;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\User\Models\User;
use SensitiveParameter;

class OwnerActivationService
{
    public function __construct(
        private OwnerActivationTokenService $tokens,
        private IdentityChallengeService $challenges,
        private OwnerTotpService $totp,
        private OwnerRecoveryCodeService $recoveryCodes,
        private IdentitySecurityEventService $events,
    ) {}

    public function inviteExistingOwner(User $user, string $firstTrustRunId, string $environment, string $installationId, string $companyId, string $propertyId): OwnerActivation
    {
        $email = mb_strtolower(trim($user->email));
        if ($user->is_active || $user->password !== null || $email !== $user->email) {
            throw ValidationException::withMessages(['email' => ['The owner identity is not eligible for activation.']]);
        }

        return DB::transaction(function () use ($user, $firstTrustRunId, $environment, $installationId, $companyId, $propertyId, $email): OwnerActivation {
            $this->assertMembership($user->id, $companyId, $propertyId, true);
            $activation = OwnerActivation::query()->create([
                'status' => OwnerActivationStatus::Invited,
                'environment' => $environment,
                'installation_id' => trim($installationId),
                'first_trust_run_id' => $firstTrustRunId,
                'user_id' => $user->id,
                'company_id' => $companyId,
                'property_id' => $propertyId,
                'canonical_email' => $email,
                'pending_role_name' => 'installation-owner',
                'version' => 1,
                'invited_at' => now(),
            ]);
            $this->events->record('OWNER_INVITED', 'SUCCESS', $this->context($activation));

            return $activation;
        });
    }

    public function issueToken(OwnerActivation $activation, string $purpose): string
    {
        if ($activation->status === OwnerActivationStatus::Active) {
            throw ValidationException::withMessages(['activation' => ['The activation is already complete.']]);
        }

        return $this->tokens->issue($activation, $purpose);
    }

    public function tokenRateLimitSubject(#[SensitiveParameter] string $token, string $purpose): string
    {
        return $this->tokens->rateLimitSubject($token, $purpose);
    }

    public function redeemEmail(#[SensitiveParameter] string $token, string $purpose): string
    {
        return DB::transaction(function () use ($token, $purpose): string {
            [, $activation] = $this->tokens->redeem($token, $purpose);
            if ($purpose === 'verify_email') {
                $this->transition($activation, OwnerActivationStatus::EmailVerified, ['email_verified_at' => now()]);
                User::query()->whereKey($activation->user_id)->lockForUpdate()->update(['email_verified_at' => now()]);
                $this->events->record('OWNER_EMAIL_VERIFIED', 'SUCCESS', $this->context($activation));
            } elseif ($activation->status === OwnerActivationStatus::Invited || $activation->status === OwnerActivationStatus::Active) {
                throw ValidationException::withMessages(['token' => ['The activation link is invalid or expired.']]);
            }

            return $this->challenges->issueActivation($activation->fresh());
        });
    }

    public function establishPassword(#[SensitiveParameter] string $challenge, #[SensitiveParameter] string $password): void
    {
        Validator::make(['password' => $password], ['password' => ['required', new PrivilegedOwnerPassword]])->validate();

        DB::transaction(function () use ($challenge, $password): void {
            [, $activation] = $this->challenges->assertActivation($challenge);
            $this->requireStatus($activation, OwnerActivationStatus::EmailVerified);
            User::query()->whereKey($activation->user_id)->lockForUpdate()->update(['password' => Hash::make($password)]);
            $this->transition($activation, OwnerActivationStatus::PasswordEstablished, ['password_established_at' => now()]);
            $this->events->record('OWNER_PASSWORD_ESTABLISHED', 'SUCCESS', $this->context($activation));
        });
    }

    public function startMfa(#[SensitiveParameter] string $challenge): string
    {
        return DB::transaction(function () use ($challenge): string {
            [, $activation] = $this->challenges->assertActivation($challenge);
            if (! in_array($activation->status, [OwnerActivationStatus::PasswordEstablished, OwnerActivationStatus::MfaEnrolling], true)) {
                throw ValidationException::withMessages(['activation' => ['The activation state is invalid.']]);
            }
            if ($activation->status === OwnerActivationStatus::PasswordEstablished) {
                $this->transition($activation, OwnerActivationStatus::MfaEnrolling, ['mfa_enrollment_started_at' => now()]);
            }
            $result = $this->totp->start($activation);
            $this->events->record('OWNER_MFA_ENROLLMENT_STARTED', 'SUCCESS', $this->context($activation));

            return $result['otpauth_uri'];
        });
    }

    public function confirmMfa(#[SensitiveParameter] string $challenge, #[SensitiveParameter] string $code): void
    {
        try {
            DB::transaction(function () use ($challenge, $code): void {
                [, $activation] = $this->challenges->assertActivation($challenge);
                $this->requireStatus($activation, OwnerActivationStatus::MfaEnrolling);
                $this->totp->confirm($activation, $code);
                $this->transition($activation, OwnerActivationStatus::MfaEnrolled, ['mfa_enrolled_at' => now()]);
                $this->events->record('OWNER_MFA_ENROLLED', 'SUCCESS', $this->context($activation));
            });
        } catch (ValidationException $exception) {
            $this->challenges->failBearer($challenge, 'ACTIVATION');

            throw $exception;
        }
    }

    public function complete(#[SensitiveParameter] string $challenge, #[SensitiveParameter] string $code): array
    {
        try {
            return DB::transaction(function () use ($challenge, $code): array {
                [$challengeRow, $activation] = $this->challenges->assertActivation($challenge);
                $this->requireStatus($activation, OwnerActivationStatus::MfaEnrolled);
                $user = User::query()->whereKey($activation->user_id)->lockForUpdate()->firstOrFail();
                $factor = $this->totp->verifyActive($user->id, $code);
                $this->assertMembership($user->id, $activation->company_id, $activation->property_id, true);
                $this->lockRoleIdentity($activation->property_id);

                $role = Role::query()->firstOrCreate([
                    'name' => 'installation-owner',
                    'guard_name' => 'web',
                    'property_id' => $activation->property_id,
                ]);
                $role->syncPermissions([]);
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $role->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                    'property_id' => $activation->property_id,
                ]);

                $codes = $this->recoveryCodes->generate($factor);
                $user->forceFill(['is_active' => true])->save();
                $this->transition($activation, OwnerActivationStatus::Active, ['activated_at' => now()]);
                $this->events->record('OWNER_ACTIVATED', 'SUCCESS', $this->context($activation));
                $this->challenges->consume($challengeRow);

                return $codes;
            });
        } catch (ValidationException $exception) {
            $this->challenges->failBearer($challenge, 'ACTIVATION');

            throw $exception;
        }
    }

    private function transition(OwnerActivation $activation, OwnerActivationStatus $next, array $timestamps): void
    {
        if ($activation->status->next() !== $next) {
            throw ValidationException::withMessages(['activation' => ['The activation state transition is invalid.']]);
        }
        $activation->forceFill(array_merge($timestamps, ['status' => $next, 'version' => $activation->version + 1]))->save();
    }

    private function requireStatus(OwnerActivation $activation, OwnerActivationStatus $expected): void
    {
        if ($activation->status !== $expected) {
            throw ValidationException::withMessages(['activation' => ['The activation state is invalid.']]);
        }
    }

    private function assertMembership(string $userId, string $companyId, string $propertyId, bool $requireDefault): void
    {
        $membership = DB::table('property_user')
            ->join('properties', 'properties.id', '=', 'property_user.property_id')
            ->where('property_user.user_id', $userId)
            ->where('property_user.property_id', $propertyId)
            ->where('properties.company_id', $companyId)
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->where('property_user.status', 'active')
            ->when($requireDefault, fn ($query) => $query->where('property_user.is_default', true))
            ->lockForUpdate()
            ->exists();
        if (! $membership) {
            throw ValidationException::withMessages(['activation' => ['The owner property binding is invalid.']]);
        }
    }

    private function lockRoleIdentity(string $propertyId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$propertyId, 'installation-owner']);
        DB::table('roles')->where('name', 'installation-owner')->where('property_id', $propertyId)->lockForUpdate()->get();
    }

    private function context(OwnerActivation $activation): array
    {
        return [
            'subject_user_id' => $activation->user_id,
            'company_id' => $activation->company_id,
            'property_id' => $activation->property_id,
            'activation_id' => $activation->id,
        ];
    }
}
