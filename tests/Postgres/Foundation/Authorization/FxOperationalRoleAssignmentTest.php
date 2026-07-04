<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Database\Seeders\RoleSeeder;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Services\FxOperationalRoleAssignmentService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FxOperationalRoleAssignmentTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $manager;
    private User $target;
    private User $outsideTarget;

    public function test_unauthorized_manager_gets_403(): void
    {
        $this->seedAuthorization();
        $this->createFixtureUsers();

        $unauthorized = $this->user('Unauthorized Manager', 'unauthorized-fx-manager@example.test');
        $this->attachProperty($unauthorized, $this->property);

        $this->withSession($this->propertySession())
            ->actingAs($unauthorized, 'web')
            ->post(route('finance.fx-operational-role-assignments.store'), [
                'target_user_id' => $this->target->id,
                'role' => 'accounts-payable-officer',
                'action' => 'assign',
                'reason' => 'Temporary AP coverage.',
            ])
            ->assertForbidden();
    }

    public function test_controlled_fx_operational_role_assignment_workflow(): void
    {
        $this->seedAuthorization();
        $this->createFixtureUsers();

        $domainCountsBefore = $this->domainTableCounts();

        foreach (FxOperationalRoleAssignmentService::APPROVED_ROLES as $roleName) {
            $response = $this->assign($this->target, $roleName);
            $response->assertRedirect()
                ->assertSessionHasNoErrors();

            $session = $response->baseResponse->getSession();
            if ($session->has('error')) {
                $this->fail('Controlled assignment error: ' . $session->get('error'));
            }

            if (!$session->has('success')) {
                $location = (string) $response->headers->get('Location');
                $path = parse_url($location, PHP_URL_PATH) ?: $location;
                $this->fail('Missing success feedback after redirect to ' . $path);
            }

            $response->assertSessionHas('success', 'FX operational role assigned.');

            $this->assertTargetHasOnlyFxRoles($this->target, [$roleName]);

            $this->revoke($this->target, $roleName)
                ->assertRedirect()
                ->assertSessionHas('success', 'FX operational role revoked.');

            $this->assertTargetHasOnlyFxRoles($this->target, []);
        }

        $this->assign($this->target, 'accounts-payable-officer')
            ->assertSessionHas('success', 'FX operational role assigned.');

        $this->assign($this->target, 'finance-controller')
            ->assertSessionHas('error', 'The target already has an FX operational role for this property.');
        $this->assertTargetHasOnlyFxRoles($this->target, ['accounts-payable-officer']);

        $this->assign($this->manager, 'finance-manager')
            ->assertSessionHas('error', 'Self-assignment and self-revocation are not allowed.');

        $this->withSession($this->propertySession())
            ->actingAs($this->manager, 'web')
            ->post(route('finance.fx-operational-role-assignments.store'), [
                'target_user_id' => $this->target->id,
                'role' => 'super-admin',
                'action' => 'assign',
                'reason' => 'Attempted broad role assignment.',
            ])
            ->assertSessionHasErrors('role');

        $this->assign($this->outsideTarget, 'finance-manager')
            ->assertSessionHas('error', 'Actor and target must both belong to the active property.');

        $this->withSession($this->propertySession())
            ->actingAs($this->manager, 'web')
            ->post(route('finance.fx-operational-role-assignments.store'), [
                'target_user_id' => $this->target->id,
                'role' => 'accounts-payable-officer',
                'action' => 'assign',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assign($this->target, 'accounts-payable-officer')
            ->assertSessionHas('error', 'The target already has the selected FX operational role.');

        $this->assertTargetStillHasRole($this->target, 'staff');
        $this->assertDatabaseCount('model_has_permissions', 0);

        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('event', 'fx_operational_role_assign')
            ->where('auditable_id', $this->target->id)
            ->count());
        $this->assertGreaterThanOrEqual(1, AuditLog::query()
            ->where('event', 'fx_operational_role_revoke')
            ->where('auditable_id', $this->target->id)
            ->count());

        $this->assertSame(
            1,
            DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $this->target->id)
                ->where('model_has_roles.property_id', $this->property->id)
                ->whereIn('roles.name', FxOperationalRoleAssignmentService::APPROVED_ROLES)
                ->count()
        );

        foreach ($domainCountsBefore as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Table {$table} mutated.");
        }
    }

    private function seedAuthorization(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->ensureLegacyRoleSeederPermissionsExist();
        $this->seed(RoleSeeder::class);
    }

    private function createFixtureUsers(): void
    {
        $this->company = Company::create([
            'name' => 'FX Test Company',
            'slug' => 'fx-test-company',
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FX Test Property',
            'slug' => 'fx-test-property',
            'code' => 'FXTP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Other FX Property',
            'slug' => 'other-fx-property',
            'code' => 'OFXP',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->manager = $this->user('FX Access Manager', 'fx-access-manager@example.test');
        $this->target = $this->user('FX Target User', 'fx-target@example.test');
        $this->outsideTarget = $this->user('Outside FX Target', 'outside-fx-target@example.test');

        $this->attachProperty($this->manager, $this->property);
        $this->attachProperty($this->target, $this->property);
        $this->attachProperty($this->outsideTarget, $this->otherProperty);

        $managerRole = Role::firstOrCreate([
            'name' => 'fx-role-assignment-manager-test',
            'guard_name' => 'web',
            'property_id' => null,
        ]);
        $managerRole->syncPermissions([FxOperationalRoleAssignmentService::MANAGE_PERMISSION]);
        $this->manager->assignRole($managerRole);
        $this->target->assignRole('staff');

        $this->assertTrue($this->manager->properties()
            ->where('properties.id', $this->property->id)
            ->where('company_id', $this->company->id)
            ->where('properties.is_active', true)
            ->where('property_user.status', 'active')
            ->exists());
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function attachProperty(User $user, Property $property): void
    {
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function assign(User $target, string $roleName)
    {
        return $this->withSession($this->propertySession())
            ->actingAs($this->manager, 'web')
            ->withHeader('X-Request-Id', 'fx-role-assignment-test')
            ->post(route('finance.fx-operational-role-assignments.store'), [
                'target_user_id' => $target->id,
                'role' => $roleName,
                'action' => 'assign',
                'reason' => 'Approved Finance coverage change.',
                'permission' => 'finance.journal-entry.post',
            ]);
    }

    private function revoke(User $target, string $roleName)
    {
        return $this->withSession($this->propertySession())
            ->actingAs($this->manager, 'web')
            ->withHeader('X-Request-Id', 'fx-role-revocation-test')
            ->post(route('finance.fx-operational-role-assignments.store'), [
                'target_user_id' => $target->id,
                'role' => $roleName,
                'action' => 'revoke',
                'reason' => 'Approved Finance coverage removal.',
            ]);
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function assertTargetHasOnlyFxRoles(User $target, array $expectedRoles): void
    {
        setPermissionsTeamId($this->property->id);
        $target->refresh();

        $actual = collect(FxOperationalRoleAssignmentService::APPROVED_ROLES)
            ->filter(fn (string $roleName) => $target->hasRole($roleName))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($expectedRoles, $actual);
    }

    private function assertTargetStillHasRole(User $target, string $roleName): void
    {
        setPermissionsTeamId($this->property->id);
        $target->refresh();

        $this->assertTrue($target->hasRole($roleName));
    }

    private function domainTableCounts(): array
    {
        $tables = [
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
                'guard_name' => 'web',
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
