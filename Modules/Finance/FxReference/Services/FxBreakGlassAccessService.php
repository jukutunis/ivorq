<?php

namespace Modules\Finance\FxReference\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;

class FxBreakGlassAccessService
{
    public const BROAD_ROLES = ['super-admin', 'property-admin'];

    private const SESSION_KEY = 'fx_break_glass_activation';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function requiresBreakGlass(User $actor): bool
    {
        return $actor->hasAnyRole(self::BROAD_ROLES);
    }

    public function activate(User $actor, string $reason, string $propertyId, ?string $companyId): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('An operational reason is required for break-glass activation.');
        }

        if (!$this->requiresBreakGlass($actor)) {
            throw new DomainException('Break-glass activation is not required for this actor.');
        }

        if (!$this->confirmationService->hasValidConfirmation($actor, 'fx-break-glass', $companyId, $propertyId)) {
            throw new DomainException('A valid FX break-glass sensitive action confirmation is required before activation.');
        }

        $expiryAt = $this->confirmationService->confirmationExpiryAt($actor, 'fx-break-glass', $companyId, $propertyId);

        $now = Carbon::now();
        $activation = [
            'actor_id' => $actor->getKey(),
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'reason' => $reason,
            'activated_at' => $now->toISOString(),
            'expires_at' => $expiryAt?->toISOString() ?? $now->addMinutes(15)->toISOString(),
        ];

        session()->put(self::SESSION_KEY, $activation);

        $this->auditService->log(
            'fx_break_glass_activated',
            $actor,
            [],
            [
                'property_id' => $propertyId,
                'company_id' => $companyId,
                'reason' => $reason,
                'correlation' => request()?->headers->get('X-Request-Id') ?? request()?->headers->get('X-Correlation-Id'),
            ],
            ['fx-break-glass', 'activated', $propertyId]
        );
    }

    public function hasValidActivation(User $actor, string $propertyId, ?string $companyId): bool
    {
        $activation = session()->get(self::SESSION_KEY);

        if (!is_array($activation)) {
            return false;
        }

        if (!isset($activation['actor_id'], $activation['property_id'], $activation['expires_at'])) {
            return false;
        }

        if ($activation['actor_id'] !== $actor->getKey()) {
            return false;
        }

        if ($activation['property_id'] !== $propertyId) {
            return false;
        }

        if (isset($activation['company_id']) && $activation['company_id'] !== null) {
            if ($activation['company_id'] !== $companyId) {
                return false;
            }
        }

        if (Carbon::now()->isAfter(Carbon::parse($activation['expires_at']))) {
            return false;
        }

        return true;
    }

    public function requireOperationalFxAccess(User $actor, string $propertyId, ?string $companyId): void
    {
        if (!$this->requiresBreakGlass($actor)) {
            return;
        }

        if (!$this->hasValidActivation($actor, $propertyId, $companyId)) {
            throw new DomainException('FX break-glass activation is required. Broad administrators must activate temporary FX operational access before using the FX workspace.');
        }
    }

    public function deactivate(User $actor, string $propertyId, ?string $companyId): void
    {
        $hadActivation = session()->has(self::SESSION_KEY);

        session()->forget(self::SESSION_KEY);

        if ($hadActivation) {
            $this->auditService->log(
                'fx_break_glass_deactivated',
                $actor,
                [],
                [
                    'property_id' => $propertyId,
                    'company_id' => $companyId,
                    'correlation' => request()?->headers->get('X-Request-Id') ?? request()?->headers->get('X-Correlation-Id'),
                ],
                ['fx-break-glass', 'deactivated', $propertyId]
            );
        }
    }

    public function activationMetadataFor(User $actor, string $propertyId, ?string $companyId): ?array
    {
        $activation = session()->get(self::SESSION_KEY);

        if (!is_array($activation)) {
            return null;
        }

        if (!isset($activation['actor_id'], $activation['property_id'], $activation['expires_at'])) {
            return null;
        }

        if ($activation['actor_id'] !== $actor->getKey()) {
            return null;
        }

        if ($activation['property_id'] !== $propertyId) {
            return null;
        }

        if (isset($activation['company_id']) && $activation['company_id'] !== null) {
            if ($activation['company_id'] !== $companyId) {
                return null;
            }
        }

        if (Carbon::now()->isAfter(Carbon::parse($activation['expires_at']))) {
            return null;
        }

        return [
            'activated_at' => $activation['activated_at'],
            'expires_at' => $activation['expires_at'],
            'reason' => $activation['reason'] ?? '',
        ];
    }
}
