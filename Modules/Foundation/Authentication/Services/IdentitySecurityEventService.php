<?php

namespace Modules\Foundation\Authentication\Services;

use InvalidArgumentException;
use Modules\Foundation\Authentication\Models\IdentitySecurityEvent;

class IdentitySecurityEventService
{
    /**
     * The policy is deliberately closed: an event, context field, or metadata
     * key not declared here cannot reach the authoritative security ledger.
     */
    private const EVENT_POLICIES = [
        'OWNER_INVITED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => [],
        ],
        'OWNER_EMAIL_VERIFIED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => [],
        ],
        'OWNER_PASSWORD_ESTABLISHED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => [],
        ],
        'OWNER_MFA_ENROLLMENT_STARTED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => [],
        ],
        'OWNER_MFA_ENROLLED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => [],
        ],
        'OWNER_ACTIVATED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => [],
        ],
        'LOGIN_PASSWORD_ACCEPTED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => ['channel' => ['enum' => ['web', 'api']]],
        ],
        'LOGIN_MFA_SUCCEEDED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['actor_user_id', 'subject_user_id', 'company_id', 'property_id', 'activation_id', 'challenge_id'],
            'metadata' => ['mfa_method' => ['enum' => ['totp', 'recovery_code']]],
        ],
        'LOGIN_MFA_FAILED' => [
            'outcomes' => ['FAILURE'],
            'context' => ['subject_user_id', 'company_id', 'property_id', 'activation_id', 'challenge_id', 'reason_code'],
            'reason_codes' => ['CHANNEL_MISMATCH', 'GUEST_SESSION_MISMATCH', 'AUTHORITY_BINDING_INVALID', 'SECOND_FACTOR_INVALID'],
            'metadata' => [
                'mfa_method' => ['enum' => ['totp', 'recovery_code']],
                'attempts_remaining' => ['integer' => [0, 5]],
            ],
        ],
        'RECOVERY_CODE_USED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['subject_user_id'],
            'metadata' => [],
        ],
        'RECOVERY_CODES_REGENERATED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['actor_user_id', 'subject_user_id', 'company_id', 'property_id', 'activation_id'],
            'metadata' => ['recovery_generation' => ['integer' => [1, 65535]]],
        ],
        'PASSWORD_RESET' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['actor_user_id', 'subject_user_id', 'company_id', 'property_id', 'activation_id', 'reason_code'],
            'reason_codes' => ['PASSWORD_CHANGE'],
            'metadata' => [],
        ],
        'SESSIONS_REVOKED' => [
            'outcomes' => ['SUCCESS'],
            'context' => ['actor_user_id', 'subject_user_id', 'company_id', 'property_id', 'reason_code'],
            'reason_codes' => ['IDENTITY_COMPROMISE', 'RECOVERY_CODES_REGENERATED', 'PASSWORD_RESET', 'PASSWORD_CHANGE', 'LOGOUT_ALL'],
            'metadata' => ['auth_epoch' => ['integer' => [0, PHP_INT_MAX]]],
        ],
        'OWNER_MFA_RESET_REQUESTED' => [
            'outcomes' => ['FAILURE'],
            'context' => ['actor_user_id', 'subject_user_id', 'company_id', 'property_id', 'activation_id', 'reason_code'],
            'reason_codes' => ['HIGH_ASSURANCE_RECOVERY_REQUIRED'],
            'metadata' => [],
        ],
    ];

    private const IDENTIFIER_CONTEXT_KEYS = [
        'actor_user_id', 'subject_user_id', 'company_id', 'property_id', 'activation_id', 'challenge_id',
    ];

    public function record(string $type, string $outcome, array $context = [], array $metadata = []): IdentitySecurityEvent
    {
        $policy = self::EVENT_POLICIES[$type] ?? null;
        if ($policy === null || ! in_array($outcome, $policy['outcomes'], true)) {
            throw new InvalidArgumentException('Identity-security event type or outcome is not approved.');
        }

        $this->assertOnlyApprovedKeys($context, array_merge($policy['context'], ['correlation_id']), 'context');
        $this->assertContextValues($context, $policy);
        $this->assertMetadata($metadata, $policy['metadata']);

        return IdentitySecurityEvent::query()->create([
            'event_type' => $type,
            'outcome' => $outcome,
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'subject_user_id' => $context['subject_user_id'] ?? null,
            'company_id' => $context['company_id'] ?? null,
            'property_id' => $context['property_id'] ?? null,
            'activation_id' => $context['activation_id'] ?? null,
            'challenge_id' => $context['challenge_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? null,
            'reason_code' => $context['reason_code'] ?? null,
            'metadata' => $metadata === [] ? new \stdClass : $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function assertOnlyApprovedKeys(array $values, array $approved, string $kind): void
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key) || ! in_array($key, $approved, true)) {
                throw new InvalidArgumentException("Identity-security {$kind} contains an unapproved field.");
            }
        }
    }

    private function assertContextValues(array $context, array $policy): void
    {
        foreach (self::IDENTIFIER_CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;
            if ($value !== null && (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1)) {
                throw new InvalidArgumentException('Identity-security context contains an invalid identifier.');
            }
        }

        if (array_key_exists('correlation_id', $context)) {
            $correlation = $context['correlation_id'];
            if ($correlation !== null && (! is_string($correlation)
                    || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9._:-]{0,98}[A-Za-z0-9])?\z/', $correlation) !== 1)) {
                throw new InvalidArgumentException('Identity-security correlation identifier is invalid.');
            }
        }

        if (array_key_exists('reason_code', $context)) {
            $reason = $context['reason_code'];
            if ($reason !== null && (! is_string($reason) || ! in_array($reason, $policy['reason_codes'] ?? [], true))) {
                throw new InvalidArgumentException('Identity-security reason code is not approved for this event.');
            }
        }
    }

    private function assertMetadata(array $metadata, array $schema): void
    {
        $this->assertOnlyApprovedKeys($metadata, array_keys($schema), 'metadata');

        foreach ($metadata as $key => $value) {
            $rule = $schema[$key];
            if (isset($rule['enum'])) {
                if (! is_string($value) || ! in_array($value, $rule['enum'], true)) {
                    throw new InvalidArgumentException('Identity-security metadata contains an invalid enum value.');
                }

                continue;
            }

            if (isset($rule['integer'])) {
                [$minimum, $maximum] = $rule['integer'];
                if (! is_int($value) || $value < $minimum || $value > $maximum) {
                    throw new InvalidArgumentException('Identity-security metadata contains an invalid integer value.');
                }

                continue;
            }

            throw new InvalidArgumentException('Identity-security metadata schema is invalid.');
        }
    }
}
