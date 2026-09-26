<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Models\OwnerActivationToken;
use Modules\Foundation\Authentication\Support\SecretBearer;
use SensitiveParameter;

class OwnerActivationTokenService
{
    private const DOMAIN = 'IVORQ-OWNER-ACTIVATION-TOKEN-V1';

    public function issue(OwnerActivation $activation, string $purpose): string
    {
        if (! in_array($purpose, ['verify_email', 'resume_activation'], true)) {
            throw new \InvalidArgumentException('Unsupported owner activation token purpose.');
        }

        return DB::transaction(function () use ($activation, $purpose): string {
            OwnerActivationToken::query()
                ->where('activation_id', $activation->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->update(['revoked_at' => now()]);

            [$token, $bytes] = SecretBearer::generate();
            OwnerActivationToken::query()->create([
                'activation_id' => $activation->id,
                'environment' => $activation->environment,
                'installation_id' => $activation->installation_id,
                'user_id' => $activation->user_id,
                'company_id' => $activation->company_id,
                'property_id' => $activation->property_id,
                'purpose' => $purpose,
                'digest' => SecretBearer::digest(self::DOMAIN, $bytes),
                'issued_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);

            return $token;
        });
    }

    public function redeem(#[SensitiveParameter] string $token, string $purpose): array
    {
        try {
            $bytes = SecretBearer::decode($token);
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }

        return DB::transaction(function () use ($bytes, $purpose): array {
            $record = OwnerActivationToken::query()
                ->where('digest', SecretBearer::digest(self::DOMAIN, $bytes))
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();
            if (! $record || $record->consumed_at !== null || $record->revoked_at !== null || $record->expires_at->isPast()) {
                throw $this->invalid();
            }

            $activation = OwnerActivation::query()->whereKey($record->activation_id)->lockForUpdate()->firstOrFail();
            foreach (['environment', 'installation_id', 'user_id', 'company_id', 'property_id'] as $field) {
                if ((string) $record->{$field} !== (string) $activation->{$field}) {
                    throw $this->invalid();
                }
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return [$record, $activation];
        });
    }

    public function rateLimitSubject(#[SensitiveParameter] string $token, string $purpose): string
    {
        try {
            $bytes = SecretBearer::decode($token);
        } catch (\InvalidArgumentException) {
            return 'invalid:'.hash('sha256', $token);
        }

        $record = OwnerActivationToken::query()
            ->select(['activation_id'])
            ->where('digest', SecretBearer::digest(self::DOMAIN, $bytes))
            ->where('purpose', $purpose)
            ->first();

        return $record ? 'activation:'.$record->activation_id : 'invalid:'.hash('sha256', $token);
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['token' => ['The activation link is invalid or expired.']]);
    }
}
