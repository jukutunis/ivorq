<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Database\Seeders\RoleSeeder;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Tests\PostgresTestCase;

class FxOperationalRoleConfigurationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private const GUARD = 'web';

    private const APPROVED_ROLE_PERMISSIONS = [
        'accounts-payable-officer' => [
            'finance.fx-adjustment.view',
            'finance.payables.ap-settlement.allocate',
            'finance.fx-adjustment-candidate.create',
        ],
        'finance-controller' => [
            'finance.fx-adjustment.view',
            'finance.journal-candidate.review',
            'finance.journal-candidate.materialize-draft',
        ],
        'finance-manager' => [
            'finance.fx-adjustment.view',
            'finance.journal-entry-draft.authorize-finalization',
        ],
        'general-ledger-accountant' => [
            'finance.fx-adjustment.view',
            'finance.journal-entry.post',
        ],
    ];

    private const EXISTING_ROLE_NAMES = [
        'super-admin',
        'property-admin',
        'general-manager',
        'staff',
        'department-head',
        'supervisor',
    ];

    public function test_fx_operational_finance_roles_are_configured_with_segregated_authority(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->ensureLegacyRoleSeederPermissionsExist();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('model_has_permissions', 0);
        $this->assertDatabaseCount('model_has_roles', 0);

        $domainCountsBefore = $this->domainTableCounts();

        $this->seed(RoleSeeder::class);

        foreach (self::EXISTING_ROLE_NAMES as $roleName) {
            $this->assertDatabaseHas('roles', [
                'name' => $roleName,
                'guard_name' => self::GUARD,
                'property_id' => null,
            ]);
        }

        foreach (self::APPROVED_ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = $this->role($roleName);

            $this->assertSame(self::GUARD, $role->guard_name);
            $this->assertNull($role->property_id);
            $this->assertEqualsCanonicalizing($permissions, $role->permissions->pluck('name')->all());
        }

        $this->assertRoleLacks('accounts-payable-officer', [
            'finance.journal-candidate.review',
            'finance.journal-candidate.materialize-draft',
            'finance.journal-entry-draft.authorize-finalization',
            'finance.journal-entry.post',
        ]);

        $this->assertRoleLacks('finance-controller', [
            'finance.payables.ap-settlement.allocate',
            'finance.fx-adjustment-candidate.create',
            'finance.journal-entry-draft.authorize-finalization',
            'finance.journal-entry.post',
        ]);

        $this->assertRoleLacks('finance-manager', [
            'finance.payables.ap-settlement.allocate',
            'finance.fx-adjustment-candidate.create',
            'finance.journal-candidate.review',
            'finance.journal-candidate.materialize-draft',
            'finance.journal-entry.post',
        ]);

        $this->assertRoleLacks('general-ledger-accountant', [
            'finance.payables.ap-settlement.allocate',
            'finance.fx-adjustment-candidate.create',
            'finance.journal-candidate.review',
            'finance.journal-candidate.materialize-draft',
            'finance.journal-entry-draft.authorize-finalization',
        ]);

        foreach (['general-manager', 'staff', 'department-head', 'supervisor'] as $roleName) {
            $this->assertRoleLacks($roleName, [
                'finance.fx-adjustment.view',
                'finance.payables.ap-settlement.allocate',
                'finance.fx-adjustment-candidate.create',
                'finance.journal-candidate.review',
                'finance.journal-candidate.materialize-draft',
                'finance.journal-entry-draft.authorize-finalization',
                'finance.journal-entry.post',
            ]);
        }

        $this->assertSame(Permission::count(), $this->role('super-admin')->permissions()->count());
        $this->assertSame(Permission::count(), $this->role('property-admin')->permissions()->count());

        foreach (array_keys(self::APPROVED_ROLE_PERMISSIONS) as $roleName) {
            $this->assertNotSame(Permission::count(), $this->role($roleName)->permissions()->count());
        }

        $roleCountAfterFirstRun = Role::count();
        $rolePermissionRowsAfterFirstRun = DB::table('role_has_permissions')->count();
        $permissionMatrixAfterFirstRun = $this->rolePermissionMatrix();

        $this->seed(RoleSeeder::class);

        $this->assertSame($roleCountAfterFirstRun, Role::count());
        $this->assertSame($rolePermissionRowsAfterFirstRun, DB::table('role_has_permissions')->count());
        $this->assertSame($permissionMatrixAfterFirstRun, $this->rolePermissionMatrix());

        $expectedRoles = array_merge(self::EXISTING_ROLE_NAMES, array_keys(self::APPROVED_ROLE_PERMISSIONS));
        $this->assertEqualsCanonicalizing($expectedRoles, Role::pluck('name')->all());

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('model_has_permissions', 0);
        $this->assertDatabaseCount('model_has_roles', 0);

        foreach ($domainCountsBefore as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function role(string $name): Role
    {
        return Role::with('permissions')
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->whereNull('property_id')
            ->firstOrFail();
    }

    private function assertRoleLacks(string $roleName, array $permissions): void
    {
        $actual = $this->role($roleName)->permissions->pluck('name')->all();

        foreach ($permissions as $permission) {
            $this->assertNotContains($permission, $actual, "{$roleName} unexpectedly has {$permission}.");
        }
    }

    private function rolePermissionMatrix(): array
    {
        return Role::with('permissions')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Role $role) => [
                $role->name => $role->permissions
                    ->pluck('name')
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    private function domainTableCounts(): array
    {
        $tables = [
            'properties',
            'companies',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'ap_settlement_allocations',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'exchange_rate_evidences',
            'payment_adjustment_configuration_evidences',
            'gl_financial_periods',
            'property_business_dates',
        ];

        return collect($tables)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    private function ensureLegacyRoleSeederPermissionsExist(): void
    {
        foreach ($this->legacyRoleSeederPermissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => self::GUARD,
            ]);
        }
    }

    private function legacyRoleSeederPermissions(): array
    {
        return [
            'zone.view',
            'zone.create',
            'zone.edit',
            'zone.assign',
            'zone.archive',
            'housekeeping.room.view',
            'housekeeping.room.create',
            'housekeeping.room.edit',
            'housekeeping.room.cleanliness',
            'housekeeping.room.occupancy',
            'housekeeping.task.view',
            'housekeeping.task.create',
            'housekeeping.task.edit',
            'housekeeping.task.assign',
            'housekeeping.task.start',
            'housekeeping.task.complete',
            'housekeeping.task.cancel',
            'housekeeping.checklist.view',
            'housekeeping.checklist.create',
            'housekeeping.checklist.edit',
            'housekeeping.inspection.view',
            'housekeeping.inspection.create',
            'housekeeping.inspection.conduct',
            'housekeeping.inspection.approve',
            'engineering.work-order.view',
            'engineering.work-order.create',
            'engineering.work-order.edit',
            'engineering.work-order.delete',
            'engineering.work-order.assign',
            'engineering.work-order.approve',
            'engineering.pm.view',
            'engineering.pm.create',
            'engineering.pm.edit',
            'engineering.pm.delete',
            'engineering.asset-request.view',
            'engineering.asset-request.create',
            'engineering.asset-request.edit',
            'engineering.asset-request.approve',
            'engineering.checklist.view',
            'engineering.checklist.create',
            'engineering.checklist.edit',
            'engineering.checklist.delete',
            'pms.guest.view',
            'pms.guest.create',
            'pms.guest.edit',
            'pms.reservation.view',
            'pms.reservation.create',
            'pms.reservation.edit',
            'pms.reservation.delete',
            'pms.reservation.checkin',
            'pms.reservation.checkout',
            'pms.room-block.view',
            'pms.room-block.create',
            'pms.room-block.edit',
            'pms.room-block.delete',
            'pms.folio.view',
            'pms.folio.manage',
            'pms.rate-plan.view',
        ];
    }
}
