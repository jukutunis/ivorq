<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\IdentityChallenge;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Support\SecretBearer;
use Modules\Foundation\User\Models\User;
use SensitiveParameter;

class IdentityChallengeService
{
    private const DOMAIN = 'IVORQ-IDENTITY-CHALLENGE-V1';

    public function issueActivation(OwnerActivation $activation): string
    {
        return $this->issue($activation->user_id, $activation->company_id, $activation->property_id, 'ACTIVATION', 'activation', $activation->id, null, null, 20);
    }

    public function issueLogin(User $user, OwnerActivation $activation, string $channel, ?string $guestSessionId): string
    {
        return $this->issue(
            $user->id,
            $activation->company_id,
            $activation->property_id,
            'LOGIN_MFA',
            $channel,
            $activation->id,
            now(),
            $guestSessionId === null ? null : hash('sha256', "IVORQ-GUEST-SESSION-V1\0".$guestSessionId),
            5,
        );
    }

    public function lockValid(#[SensitiveParameter] string $bearer, string $purpose): IdentityChallenge
    {
        try {
            $bytes = SecretBearer::decode($bearer);
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }

        $challenge = IdentityChallenge::query()
            ->where('digest', SecretBearer::digest(self::DOMAIN, $bytes))
            ->where('purpose', $purpose)
            ->lockForUpdate()
            ->first();

        if (! $challenge || $challenge->consumed_at !== null || $challenge->expires_at->isPast() || $challenge->failed_attempts >= $challenge->max_attempts) {
            throw $this->invalid();
        }

        return $challenge;
    }

    public function assertActivation(#[SensitiveParameter] string $bearer): array
    {
        return DB::transaction(function () use ($bearer): array {
            $challenge = $this->lockValid($bearer, 'ACTIVATION');
            $activation = OwnerActivation::query()->whereKey($challenge->activation_id)->lockForUpdate()->firstOrFail();

            if ((string) $activation->user_id !== (string) $challenge->user_id
                || (string) $activation->company_id !== (string) $challenge->company_id
                || (string) $activation->property_id !== (string) $challenge->property_id) {
                throw $this->invalid();
            }

            return [$challenge, $activation];
        });
    }

    public function fail(IdentityChallenge $challenge): void
    {
        $challenge->forceFill(['failed_attempts' => $challenge->failed_attempts + 1])->save();
    }

    public function failBearer(#[SensitiveParameter] string $bearer, string $purpose): void
    {
        DB::transaction(function () use ($bearer, $purpose): void {
            try {
                $challenge = $this->lockValid($bearer, $purpose);
            } catch (ValidationException) {
                return;
            }

            $this->fail($challenge);
        });
    }

    public function consume(IdentityChallenge $challenge): void
    {
        $challenge->forceFill(['consumed_at' => now()])->save();
    }

    private function issue(string $userId, string $companyId, string $propertyId, string $purpose, string $channel, ?string $activationId, mixed $passwordVerifiedAt, ?string $guestDigest, int $ttlMinutes): string
    {
        [$bearer, $bytes] = SecretBearer::generate();
        IdentityChallenge::query()->create([
            'activation_id' => $activationId,
            'user_id' => $userId,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'purpose' => $purpose,
            'channel' => $channel,
            'digest' => SecretBearer::digest(self::DOMAIN, $bytes),
            'guest_session_digest' => $guestDigest,
            'password_verified_at' => $passwordVerifiedAt,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'failed_attempts' => 0,
            'max_attempts' => 5,
        ]);

        return $bearer;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['challenge' => ['The challenge is invalid or expired.']]);
    }
}
