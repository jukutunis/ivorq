<?php

namespace Modules\Foundation\Authentication\Services;

use InvalidArgumentException;
use Modules\Foundation\Authentication\Models\IdentitySecurityEvent;

class IdentitySecurityEventService
{
    private const FORBIDDEN_KEYS = [
        'url', 'query', 'password', 'token', 'activation_token', 'challenge', 'totp',
        'seed', 'secret', 'recovery_code', 'sanctum_token', 'session_id', 'credential',
    ];

    public function record(string $type, string $outcome, array $context = [], array $metadata = []): IdentitySecurityEvent
    {
        $this->assertSafe($metadata);

        return IdentitySecurityEvent::query()->create([
            'event_type' => $type,
            'outcome' => $outcome,
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'subject_user_id' => $context['subject_user_id'] ?? null,
            'company_id' => $context['company_id'] ?? null,
            'property_id' => $context['property_id'] ?? null,
            'activation_id' => $context['activation_id'] ?? null,
            'challenge_id' => $context['challenge_id'] ?? null,
            'correlation_id' => isset($context['correlation_id']) ? substr((string) $context['correlation_id'], 0, 100) : null,
            'reason_code' => isset($context['reason_code']) ? substr((string) $context['reason_code'], 0, 100) : null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function assertSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $normalized = is_string($key) ? strtolower(str_replace(['-', ' '], '_', $key)) : '';
            if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException('Identity-security metadata contains a prohibited field.');
            }

            if (is_array($value)) {
                $this->assertSafe($value);
            }
        }
    }
}
