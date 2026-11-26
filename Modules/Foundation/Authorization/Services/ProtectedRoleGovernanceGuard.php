<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authorization\Models\Role;

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

    public function assertGenericMutationAllowed(Role $role, array $requestedPermissions = []): void
    {
        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)
            || $role->property_id === null
            || array_intersect($role->loadMissing('permissions')->permissions->pluck('name')->all(), self::PROTECTED_PERMISSIONS) !== []
            || array_intersect($requestedPermissions, self::PROTECTED_PERMISSIONS) !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'This role or permission requires its governed authority path.',
            ]);
        }
    }
}
