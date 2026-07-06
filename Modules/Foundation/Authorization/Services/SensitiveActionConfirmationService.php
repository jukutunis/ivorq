<?php

namespace Modules\Foundation\Authorization\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\User\Models\User;

class SensitiveActionConfirmationService
{
    public const CONFIRMATION_TTL_MINUTES = 15;

    public const REGISTERED_INTENTS = [
        'finance-role-assignment',
        'finance-approval',
        'fx-break-glass',
        'administrative-sensitive-action',
        'cash-payment-execution',
        'bank-payment-execution',
    ];

    private const SESSION_KEY = 'sensitive_action_confirmation';

    public function __construct(private readonly AuditService $auditService) {}

    public function registeredIntents(): array
    {
        return self::REGISTERED_INTENTS;
    }

    public function confirm(User $actor, string $intent, string $password, ?string $companyId, string $propertyId): void
    {
        if (!in_array($intent, self::REGISTERED_INTENTS, true)) {
            throw new DomainException('The requested intent is not registered.');
        }

        if (!Hash::check($password, $actor->password)) {
            throw new DomainException('The password is incorrect.');
        }

        $now = Carbon::now();
        $metadata = [
            'actor_id' => $actor->getKey(),
            'intent' => $intent,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'confirmed_at' => $now->toISOString(),
            'expires_at' => $now->copy()->addMinutes(self::CONFIRMATION_TTL_MINUTES)->toISOString(),
        ];

        $confirmations = session()->get(self::SESSION_KEY, []);
        $confirmations[$intent] = $metadata;
        session()->put(self::SESSION_KEY, $confirmations);

        $this->auditService->log(
            'sensitive_action_confirmed',
            $actor,
            [],
            [
                'intent' => $intent,
                'property_id' => $propertyId,
                'company_id' => $companyId,
                'correlation' => request()?->headers->get('X-Request-Id') ?? request()?->headers->get('X-Correlation-Id'),
            ],
            ['sensitive-action-confirmation', $intent, $propertyId]
        );
    }

    public function hasValidConfirmation(User $actor, string $intent, ?string $companyId, string $propertyId): bool
    {
        $confirmations = session()->get(self::SESSION_KEY, []);

        if (!isset($confirmations[$intent]) || !is_array($confirmations[$intent])) {
            return false;
        }

        $metadata = $confirmations[$intent];

        if (!isset($metadata['actor_id'], $metadata['intent'], $metadata['property_id'], $metadata['expires_at'])) {
            return false;
        }

        if ($metadata['actor_id'] !== $actor->getKey()) {
            return false;
        }

        if ($metadata['intent'] !== $intent) {
            return false;
        }

        if ($metadata['property_id'] !== $propertyId) {
            return false;
        }

        if (isset($metadata['company_id']) && $metadata['company_id'] !== null) {
            if ($metadata['company_id'] !== $companyId) {
                return false;
            }
        }

        if (Carbon::now()->isAfter(Carbon::parse($metadata['expires_at']))) {
            return false;
        }

        return true;
    }

    public function requireValidConfirmation(User $actor, string $intent, ?string $companyId, string $propertyId): void
    {
        if (!$this->hasValidConfirmation($actor, $intent, $companyId, $propertyId)) {
            throw new DomainException('Sensitive action confirmation is required and not currently valid.');
        }
    }

    public function invalidate(User $actor, string $intent, ?string $companyId, string $propertyId): void
    {
        $confirmations = session()->get(self::SESSION_KEY, []);

        if (isset($confirmations[$intent])) {
            unset($confirmations[$intent]);
            session()->put(self::SESSION_KEY, $confirmations);
        }

        $this->auditService->log(
            'sensitive_action_invalidated',
            $actor,
            [],
            [
                'intent' => $intent,
                'property_id' => $propertyId,
                'company_id' => $companyId,
                'correlation' => request()?->headers->get('X-Request-Id') ?? request()?->headers->get('X-Correlation-Id'),
            ],
            ['sensitive-action-invalidation', $intent, $propertyId]
        );
    }

    public function confirmationMetadataFor(User $actor, string $intent, ?string $companyId, string $propertyId): ?array
    {
        $confirmations = session()->get(self::SESSION_KEY, []);

        if (!isset($confirmations[$intent]) || !is_array($confirmations[$intent])) {
            return null;
        }

        $metadata = $confirmations[$intent];

        if (!isset($metadata['actor_id'], $metadata['intent'], $metadata['property_id'], $metadata['expires_at'])) {
            return null;
        }

        if ($metadata['actor_id'] !== $actor->getKey()) {
            return null;
        }

        if ($metadata['intent'] !== $intent) {
            return null;
        }

        if ($metadata['property_id'] !== $propertyId) {
            return null;
        }

        if (isset($metadata['company_id']) && $metadata['company_id'] !== null) {
            if ($metadata['company_id'] !== $companyId) {
                return null;
            }
        }

        if (Carbon::now()->isAfter(Carbon::parse($metadata['expires_at']))) {
            return null;
        }

        return [
            'intent' => $metadata['intent'],
            'confirmed_at' => $metadata['confirmed_at'],
            'expires_at' => $metadata['expires_at'],
        ];
    }

    public function confirmationExpiryAt(User $actor, string $intent, ?string $companyId, string $propertyId): ?Carbon
    {
        $metadata = $this->confirmationMetadataFor($actor, $intent, $companyId, $propertyId);

        if ($metadata === null || !isset($metadata['expires_at'])) {
            return null;
        }

        return Carbon::parse($metadata['expires_at']);
    }
}
