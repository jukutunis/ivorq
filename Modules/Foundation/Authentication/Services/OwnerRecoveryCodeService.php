<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\OwnerMfaFactor;
use Modules\Foundation\Authentication\Models\OwnerRecoveryCode;
use ParagonIE\ConstantTime\Base32;
use SensitiveParameter;

class OwnerRecoveryCodeService
{
    private const DOMAIN = 'IVORQ-OWNER-RECOVERY-CODE-V1';

    public function generate(OwnerMfaFactor $factor): array
    {
        $generation = ((int) OwnerRecoveryCode::query()->where('factor_id', $factor->id)->max('generation')) + 1;
        $plain = [];

        for ($ordinal = 1; $ordinal <= 10; $ordinal++) {
            $canonical = Base32::encodeUpperUnpadded(random_bytes(16));
            $plain[] = implode('-', str_split($canonical, 4));
            OwnerRecoveryCode::query()->create([
                'factor_id' => $factor->id,
                'user_id' => $factor->user_id,
                'generation' => $generation,
                'ordinal' => $ordinal,
                'digest' => $this->digest($canonical),
            ]);
        }

        return $plain;
    }

    public function claim(string $userId, #[SensitiveParameter] string $code): OwnerRecoveryCode
    {
        $canonical = strtoupper(str_replace('-', '', trim($code)));
        if (strlen($canonical) !== 26 || preg_match('/\A[A-Z2-7]+\z/', $canonical) !== 1) {
            throw $this->invalid();
        }

        $recovery = OwnerRecoveryCode::query()
            ->where('user_id', $userId)
            ->where('digest', $this->digest($canonical))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();
        if (! $recovery) {
            throw $this->invalid();
        }

        $recovery->forceFill(['used_at' => now()])->save();

        return $recovery;
    }

    public function regenerate(OwnerMfaFactor $factor): array
    {
        OwnerRecoveryCode::query()
            ->where('factor_id', $factor->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $this->generate($factor);
    }

    private function digest(string $canonical): string
    {
        return hash('sha256', self::DOMAIN."\0".$canonical);
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
    }
}
