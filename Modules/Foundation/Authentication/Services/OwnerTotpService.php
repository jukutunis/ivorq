<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Models\OwnerMfaFactor;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

class OwnerTotpService
{
    public function __construct(private IdentitySecurityEventService $events) {}

    public function start(OwnerActivation $activation): array
    {
        return DB::transaction(function () use ($activation): array {
            OwnerMfaFactor::query()
                ->where('user_id', $activation->user_id)
                ->where('state', 'PENDING')
                ->lockForUpdate()
                ->update(['state' => 'REVOKED', 'revoked_at' => now()]);

            $google = $this->google();
            $secret = $google->generateSecretKey(32);
            $factor = OwnerMfaFactor::query()->create([
                'activation_id' => $activation->id,
                'user_id' => $activation->user_id,
                'encrypted_secret' => Crypt::encryptString($secret),
                'state' => 'PENDING',
                'enrollment_started_at' => now(),
            ]);

            return [
                'factor' => $factor,
                'otpauth_uri' => $google->getQRCodeUrl('IVORQ', $activation->canonical_email, $secret),
            ];
        });
    }

    public function confirm(OwnerActivation $activation, #[SensitiveParameter] string $code): OwnerMfaFactor
    {
        return DB::transaction(function () use ($activation, $code): OwnerMfaFactor {
            $factor = OwnerMfaFactor::query()
                ->where('activation_id', $activation->id)
                ->where('state', 'PENDING')
                ->lockForUpdate()
                ->firstOrFail();

            $this->acceptCounter($factor, $code);
            $factor->forceFill(['state' => 'ACTIVE', 'confirmed_at' => now()])->save();

            return $factor;
        });
    }

    public function verifyActive(string $userId, #[SensitiveParameter] string $code): OwnerMfaFactor
    {
        $factor = OwnerMfaFactor::query()
            ->where('user_id', $userId)
            ->where('state', 'ACTIVE')
            ->lockForUpdate()
            ->firstOrFail();
        $this->acceptCounter($factor, $code);

        return $factor;
    }

    public function currentCode(OwnerMfaFactor $factor, ?int $counter = null): string
    {
        $google = $this->google();
        $secret = Crypt::decryptString($factor->encrypted_secret);

        return $google->oathTotp($secret, $counter ?? $google->getTimestamp());
    }

    private function acceptCounter(OwnerMfaFactor $factor, #[SensitiveParameter] string $code): int
    {
        if (preg_match('/\A\d{6}\z/', $code) !== 1) {
            throw $this->invalid();
        }

        $google = $this->google();
        $secret = Crypt::decryptString($factor->encrypted_secret);
        $old = $factor->last_accepted_counter ?? ($google->getTimestamp() - 2);
        $matched = $google->verifyKeyNewer($secret, $code, $old, 1);
        if ($matched === false) {
            throw $this->invalid();
        }

        $factor->forceFill(['last_accepted_counter' => $matched])->save();

        return $matched;
    }

    private function google(): Google2FA
    {
        $google = new Google2FA;
        $google->setAlgorithm('sha1');
        $google->setOneTimePasswordLength(6);
        $google->setKeyRegeneration(30);
        $google->setWindow(1);

        return $google;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
    }
}
