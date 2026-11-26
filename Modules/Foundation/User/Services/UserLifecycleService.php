<?php

namespace Modules\Foundation\User\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authentication\Services\IdentitySecurityEventService;
use Modules\Foundation\Authentication\Services\SessionRevocationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Exceptions\NotFoundException;

class UserLifecycleService
{
    public function __construct(
        private SessionRevocationService $revocation,
        private IdentitySecurityEventService $events,
        private UserAdministrationGuard $administrationGuard,
    ) {}

    public function deactivate(User $actor, string $targetId, string $propertyId): User
    {
        return DB::transaction(function () use ($actor, $targetId, $propertyId): User {
            [$target, $property] = $this->lockScopedTarget($targetId, $propertyId);
            $this->denySelf($actor, $target, 'SELF_DEACTIVATION_NOT_ALLOWED');
            $this->administrationGuard->assertOrdinaryMutationAllowed($target, $property);

            if (! $target->is_active) {
                return $target;
            }

            $target->forceFill(['is_active' => false])->save();
            $target = $this->revocation->revokeAll($target, 'USER_DEACTIVATED', $this->context($actor, $target, $property));
            $this->events->record('USER_DEACTIVATED', 'SUCCESS', $this->context($actor, $target, $property));

            return $target;
        });
    }

    public function activate(User $actor, string $targetId, string $propertyId): User
    {
        return DB::transaction(function () use ($actor, $targetId, $propertyId): User {
            [$target, $property] = $this->lockScopedTarget($targetId, $propertyId);
            $this->administrationGuard->assertOrdinaryMutationAllowed($target, $property);
            if ($target->is_active) {
                return $target;
            }

            $memberships = DB::table('property_user')
                ->join('properties', 'properties.id', '=', 'property_user.property_id')
                ->where('property_user.user_id', $target->id)
                ->where('properties.company_id', $property->company_id)
                ->where('properties.is_active', true)
                ->whereNull('properties.deleted_at')
                ->where('property_user.status', 'active')
                ->lockForUpdate()
                ->get(['property_user.property_id', 'property_user.is_default']);

            if ($memberships->isEmpty() || $memberships->where('is_default', true)->count() !== 1) {
                throw new DomainException('ACTIVE_USER_REQUIRES_ONE_VALID_DEFAULT_MEMBERSHIP');
            }

            $target->forceFill(['is_active' => true])->save();
            $this->events->record('USER_REACTIVATED', 'SUCCESS', $this->context($actor, $target, $property));

            return $target->fresh();
        });
    }

    public function offboard(User $actor, string $targetId, string $propertyId): User
    {
        return DB::transaction(function () use ($actor, $targetId, $propertyId): User {
            [$target, $property] = $this->lockScopedTarget($targetId, $propertyId, true);
            $this->administrationGuard->assertOrdinaryMutationAllowed($target, $property);
            if ($target->trashed()) {
                return $target;
            }

            $this->denySelf($actor, $target, 'SELF_OFFBOARDING_NOT_ALLOWED');
            $target->forceFill(['is_active' => false])->save();
            $target = $this->revocation->revokeAll($target, 'USER_OFFBOARDED', $this->context($actor, $target, $property));

            DB::table('property_user')->where('user_id', $target->id)->update([
                'status' => 'inactive',
                'is_default' => false,
                'updated_at' => now(),
            ]);
            $this->removePropertyAuthority($target);
            $target->delete();
            $this->events->record('USER_OFFBOARDED', 'SUCCESS', $this->context($actor, $target, $property));

            return $target;
        });
    }

    public function removeMembership(User $actor, string $targetId, string $propertyId, ?string $replacementPropertyId): User
    {
        return DB::transaction(function () use ($actor, $targetId, $propertyId, $replacementPropertyId): User {
            [$target, $property] = $this->lockScopedTarget($targetId, $propertyId);
            $this->administrationGuard->assertOrdinaryMutationAllowed($target, $property);
            $memberships = DB::table('property_user')
                ->join('properties', 'properties.id', '=', 'property_user.property_id')
                ->where('property_user.user_id', $target->id)
                ->where('properties.company_id', $property->company_id)
                ->where('property_user.status', 'active')
                ->lockForUpdate()
                ->get(['property_user.property_id', 'property_user.is_default']);
            $current = $memberships->firstWhere('property_id', $propertyId);
            if ($current === null) {
                throw new NotFoundException('Property membership');
            }

            $replacement = $replacementPropertyId === null
                ? null
                : $memberships->firstWhere('property_id', $replacementPropertyId);
            if ($replacementPropertyId !== null && ($replacement === null || $replacementPropertyId === $propertyId)) {
                throw new DomainException('REPLACEMENT_MEMBERSHIP_INVALID');
            }
            if ($target->is_active && $memberships->count() === 1) {
                throw new DomainException('ACTIVE_USER_LAST_MEMBERSHIP_CANNOT_BE_REMOVED');
            }
            if ($current->is_default && $replacement === null) {
                throw new DomainException('DEFAULT_MEMBERSHIP_REPLACEMENT_REQUIRED');
            }

            DB::table('property_user')->where(['user_id' => $target->id, 'property_id' => $propertyId])->update([
                'status' => 'inactive',
                'is_default' => false,
                'updated_at' => now(),
            ]);
            if ($replacement !== null) {
                $this->setDefaultMembershipRows($target, $property->company_id, $replacementPropertyId);
            }
            $this->removePropertyAuthority($target, $propertyId);
            $target = $this->revocation->revokeAll($target, 'MEMBERSHIP_CHANGED', $this->context($actor, $target, $property));
            $this->events->record('PROPERTY_MEMBERSHIP_REMOVED', 'SUCCESS', $this->context($actor, $target, $property));

            return $target;
        });
    }

    public function setDefaultProperty(User $actor, string $targetId, string $currentPropertyId, string $defaultPropertyId): User
    {
        return DB::transaction(function () use ($actor, $targetId, $currentPropertyId, $defaultPropertyId): User {
            [$target, $currentProperty] = $this->lockScopedTarget($targetId, $currentPropertyId);
            $this->administrationGuard->assertOrdinaryMutationAllowed($target, $currentProperty);
            $replacement = Property::query()
                ->whereKey($defaultPropertyId)
                ->where('company_id', $currentProperty->company_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            if (! $replacement || ! DB::table('property_user')->where([
                'user_id' => $target->id,
                'property_id' => $replacement->id,
                'status' => 'active',
            ])->lockForUpdate()->exists()) {
                throw new DomainException('DEFAULT_MEMBERSHIP_INVALID');
            }

            $alreadyDefault = DB::table('property_user')->where([
                'user_id' => $target->id,
                'property_id' => $replacement->id,
                'status' => 'active',
                'is_default' => true,
            ])->exists();
            if ($alreadyDefault) {
                return $target;
            }

            $this->setDefaultMembershipRows($target, $currentProperty->company_id, $replacement->id);
            $target = $this->revocation->revokeAll($target, 'MEMBERSHIP_CHANGED', $this->context($actor, $target, $replacement));
            $this->events->record('DEFAULT_PROPERTY_CHANGED', 'SUCCESS', $this->context($actor, $target, $replacement));

            return $target;
        });
    }

    private function lockScopedTarget(string $targetId, string $propertyId, bool $withTrashed = false): array
    {
        $property = Property::query()->whereKey($propertyId)->where('is_active', true)->whereNull('deleted_at')->first();
        if (! $property || ! $property->company()->where('is_active', true)->whereNull('deleted_at')->exists()) {
            throw new NotFoundException('Property');
        }

        $query = $withTrashed ? User::withTrashed() : User::query();
        $target = $query
            ->whereKey($targetId)
            ->whereHas('properties', fn ($membership) => $membership->where('properties.id', $propertyId))
            ->lockForUpdate()
            ->first();
        if (! $target) {
            throw new NotFoundException('User');
        }

        return [$target, $property];
    }

    private function denySelf(User $actor, User $target, string $code): void
    {
        if ($actor->id === $target->id) {
            throw new DomainException($code);
        }
    }

    private function setDefaultMembershipRows(User $target, string $companyId, string $defaultPropertyId): void
    {
        DB::table('property_user')
            ->where('user_id', $target->id)
            ->whereIn('property_id', Property::query()->where('company_id', $companyId)->select('id'))
            ->update(['is_default' => false, 'updated_at' => now()]);
        DB::table('property_user')->where([
            'user_id' => $target->id,
            'property_id' => $defaultPropertyId,
            'status' => 'active',
        ])->update(['is_default' => true, 'updated_at' => now()]);
    }

    private function removePropertyAuthority(User $target, ?string $propertyId = null): void
    {
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)
                ->where('model_id', $target->id)
                ->where('model_type', $target->getMorphClass())
                ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
                ->delete();
        }
    }

    private function context(User $actor, User $target, Property $property): array
    {
        return [
            'actor_user_id' => $actor->id,
            'subject_user_id' => $target->id,
            'company_id' => $property->company_id,
            'property_id' => $property->id,
        ];
    }
}
