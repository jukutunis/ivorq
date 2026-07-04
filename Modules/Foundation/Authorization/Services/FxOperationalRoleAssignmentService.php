<?php

namespace Modules\Foundation\Authorization\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\User\Models\User;

class FxOperationalRoleAssignmentService
{
    public const MANAGE_PERMISSION = 'system.finance-role-assignment.manage';

    public const APPROVED_ROLES = [
        'accounts-payable-officer',
        'finance-controller',
        'finance-manager',
        'general-ledger-accountant',
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public function assign(User $actor, User $target, string $roleName, string $propertyId, string $reason): void
    {
        $this->mutate('assign', $actor, $target, $roleName, $propertyId, $reason);
    }

    public function revoke(User $actor, User $target, string $roleName, string $propertyId, string $reason): void
    {
        $this->mutate('revoke', $actor, $target, $roleName, $propertyId, $reason);
    }

    public function targetRolesForProperty(User $target, string $propertyId): array
    {
        return collect(self::APPROVED_ROLES)
            ->filter(function (string $roleName) use ($target, $propertyId): bool {
                return $this->withTeam($propertyId, fn () => $target->hasRole($roleName));
            })
            ->values()
            ->all();
    }

    private function mutate(string $action, User $actor, User $target, string $roleName, string $propertyId, string $reason): void
    {
        $reason = trim($reason);

        if (!in_array($roleName, self::APPROVED_ROLES, true)) {
            throw new DomainException('Only approved FX operational roles may be assigned or revoked.');
        }

        if ($reason === '') {
            throw new DomainException('An operational reason is required.');
        }

        if ($actor->getKey() === $target->getKey()) {
            throw new DomainException('Self-assignment and self-revocation are not allowed.');
        }

        if (!$this->hasActivePropertyMembership($actor, $propertyId) || !$this->hasActivePropertyMembership($target, $propertyId)) {
            throw new DomainException('Actor and target must both belong to the active property.');
        }

        $this->withTeam($propertyId, function () use ($actor, $target, $roleName, $propertyId, $reason, $action): void {
            if (!$actor->can(self::MANAGE_PERMISSION)) {
                abort(403, 'Unauthorized.');
            }

            $beforeRoles = $this->targetRolesForProperty($target, $propertyId);
            $hasRequestedRole = in_array($roleName, $beforeRoles, true);

            if ($action === 'assign') {
                if ($hasRequestedRole) {
                    throw new DomainException('The target already has the selected FX operational role.');
                }

                if ($beforeRoles !== []) {
                    throw new DomainException('The target already has an FX operational role for this property.');
                }

                $target->assignRole($roleName);
            } else {
                if (!$hasRequestedRole) {
                    throw new DomainException('The target does not have the selected FX operational role.');
                }

                $target->removeRole($roleName);
            }

            $target->refresh();
            $afterRoles = $this->targetRolesForProperty($target, $propertyId);

            $this->auditService->log(
                "fx_operational_role_{$action}",
                $target,
                [
                    'fx_operational_roles' => $beforeRoles,
                ],
                [
                    'fx_operational_roles' => $afterRoles,
                    'actor_id' => $actor->getKey(),
                    'target_id' => $target->getKey(),
                    'role' => $roleName,
                    'property_id' => $propertyId,
                    'action' => $action,
                    'reason' => $reason,
                    'correlation' => request()?->headers->get('X-Request-Id') ?? request()?->headers->get('X-Correlation-Id'),
                    'recorded_at' => Carbon::now()->toISOString(),
                ],
                [
                    'fx-operational-role-assignment',
                    $action,
                    $roleName,
                    $propertyId,
                ]
            );
        });
    }

    private function hasActivePropertyMembership(User $user, string $propertyId): bool
    {
        return $user->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function withTeam(string $propertyId, callable $callback): mixed
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($propertyId);

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
