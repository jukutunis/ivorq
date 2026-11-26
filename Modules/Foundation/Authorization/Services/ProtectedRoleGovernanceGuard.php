<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProtectedRoleGovernanceGuard
{
    // Source-controlled roles and the accepted installation-owner role are not
    // definitions for generic Property role administration to recreate.
    private const PROTECTED_ROLE_NAMES = [
        'super-admin', 'property-admin', 'general-manager', 'staff',
        'department-head', 'supervisor', 'installation-owner',
        'accounts-payable-officer', 'finance-controller',
        'finance-manager', 'general-ledger-accountant',
    ];

    // These permissions are granted by the accepted FX role matrix and its
    // assignment manager. Enterprise-wide privileged taxonomy remains deferred.
    private const PROTECTED_PERMISSIONS = [
        FxOperationalRoleAssignmentService::MANAGE_PERMISSION,
        'finance.fx-adjustment.view',
        'finance.payables.ap-settlement.allocate',
        'finance.fx-adjustment-candidate.create',
        'finance.journal-candidate.review',
        'finance.journal-candidate.materialize-draft',
        'finance.banking.migration.view',
        'finance.banking.migration.mapping.review',
        'finance.banking.migration.pilot.authorization.review',
        'finance.journal-entry-draft.authorize-finalization',
        'finance.banking.migration.manage',
        'finance.banking.migration.pilot.execution.execute',
        'finance.journal-entry.post',
    ];

    public function assertGenericNameAllowed(string $name): void
    {
        if (in_array($name, self::PROTECTED_ROLE_NAMES, true)) {
            throw ValidationException::withMessages([
                'name' => 'Source-controlled and installation-owner role names cannot be created here.',
            ]);
        }
    }

    public function assertGenericMutationAllowed(Role $role, array $requestedPermissions = []): array
    {
        $canonicalNames = $this->canonicalPermissionNames($role, $requestedPermissions);

        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)
            || $role->property_id === null
            || array_intersect($role->loadMissing('permissions')->permissions->pluck('name')->all(), self::PROTECTED_PERMISSIONS) !== []
            || array_intersect($canonicalNames, self::PROTECTED_PERMISSIONS) !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'This role or permission requires its governed authority path.',
            ]);
        }

        return $canonicalNames;
    }

    private function canonicalPermissionNames(Role $role, array $requestedPermissions): array
    {
        if ($role->guard_name !== 'web' || ! array_is_list($requestedPermissions)) {
            throw ValidationException::withMessages([
                'permissions' => 'Permissions must be existing canonical web permission names.',
            ]);
        }

        foreach ($requestedPermissions as $permission) {
            if (! is_string($permission) || $permission === ''
                || trim($permission) !== $permission || PermissionRegistrar::isUid($permission)) {
                throw ValidationException::withMessages([
                    'permissions' => 'Permissions must be existing canonical web permission names.',
                ]);
            }
        }

        $names = array_values(array_unique($requestedPermissions));
        if ($names !== [] && Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->count() !== count($names)) {
            throw ValidationException::withMessages([
                'permissions' => 'Permissions must be existing canonical web permission names.',
            ]);
        }

        return $names;
    }
}
