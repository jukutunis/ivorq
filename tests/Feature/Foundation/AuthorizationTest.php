<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── Super Admin Gate Bypass ────────────────────────────────────────────

    public function test_super_admin_bypasses_all_gates(): void
    {
        $super = $this->createSuperAdmin();

        // Verified by Gate::before() in AuthorizationServiceProvider
        // Test: super-admin can access every protected route
        $this->actingAs($super)->get('/users')->assertOk();
        $this->actingAs($super)->get('/roles')->assertOk();
        $this->actingAs($super)->get('/departments')->assertOk();
        $this->actingAs($super)->get('/properties')->assertOk();
    }

    public function test_super_admin_has_null_property_id(): void
    {
        $super = $this->createSuperAdmin();

        $this->assertNull($super->property_id);
        $this->assertTrue($super->isSuperAdmin());
    }

    // ─── Spatie Teams — Property Scoping ────────────────────────────────────

    public function test_user_role_is_scoped_to_their_property(): void
    {
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $propB    = $this->createProperty($company, ['code' => 'PB30']);
        $adminA   = $this->createPropertyAdmin($propA);

        // Within propA context, adminA has the property-admin role
        setPermissionsTeamId($propA->id);
        $this->assertTrue($adminA->hasRole('property-admin'));

        // Within propB context, adminA does NOT have the role
        setPermissionsTeamId($propB->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($adminA->fresh()->hasRole('property-admin'));
    }

    public function test_permissions_are_enforced_per_property(): void
    {
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $propB    = $this->createProperty($company, ['code' => 'PB31']);
        $deptB    = $this->createDepartment($propB);

        // Admin from propA cannot view propB's department
        $adminA = $this->createPropertyAdmin($propA);

        $this->actingAs($adminA)
            ->get("/departments/{$deptB->id}")
            ->assertNotFound(); // BelongsToProperty scope hides it — resolves to 404
    }

    // ─── Role Management ────────────────────────────────────────────────────

    public function test_staff_cannot_access_role_management(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/roles')->assertForbidden();
    }

    public function test_property_admin_can_access_role_management(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/roles')->assertOk();
    }

    public function test_super_admin_can_create_role(): void
    {
        $super = $this->createSuperAdmin();
        $this->seedPermissionsAndRoles();

        $this->actingAs($super)->post('/roles', [
            'name'        => 'housekeeping-supervisor',
            'permissions' => ['department.view'],
        ])->assertRedirect('/roles');

        $this->assertDatabaseHas('roles', ['name' => 'housekeeping-supervisor']);
    }

    public function test_staff_cannot_create_role(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->post('/roles', [
            'name' => 'evil-role',
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'evil-role']);
    }

    public function test_permissions_are_visible_to_admin(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/permissions')->assertOk();
    }

    // ─── Role → Permission inheritance ──────────────────────────────────────

    public function test_user_inherits_permissions_from_assigned_role(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        setPermissionsTeamId($prop->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($admin->hasPermissionTo('department.view'));
        $this->assertTrue($admin->hasPermissionTo('user.create'));
        $this->assertTrue($admin->hasPermissionTo('role.view'));
    }

    public function test_staff_role_has_limited_permissions(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        setPermissionsTeamId($prop->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($staff->hasPermissionTo('user.create'));
        $this->assertFalse($staff->hasPermissionTo('department.delete'));
        $this->assertFalse($staff->hasPermissionTo('role.create'));
    }

    public function test_role_sync_updates_permissions(): void
    {
        $super = $this->createSuperAdmin();
        $this->seedPermissionsAndRoles();

        // Create a new role
        $this->actingAs($super)->post('/roles', ['name' => 'custom-role']);

        $role = Role::where('name', 'custom-role')->first();
        $this->assertNotNull($role);

        // Sync permissions onto it
        $this->actingAs($super)->put("/roles/{$role->id}", [
            'permissions' => ['department.view', 'user.view'],
        ])->assertRedirect('/roles');

        $role->refresh();
        $permissionNames = $role->permissions->pluck('name')->toArray();
        $this->assertContains('department.view', $permissionNames);
        $this->assertContains('user.view', $permissionNames);
    }

    // ─── Role create / edit pages ────────────────────────────────────────────

    public function test_admin_can_access_role_create_form(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/roles/create')->assertOk();
    }

    public function test_staff_cannot_access_role_create_form(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/roles/create')->assertForbidden();
    }

    public function test_admin_can_access_role_edit_form(): void
    {
        $super = $this->createSuperAdmin();
        $this->seedPermissionsAndRoles();

        $role = Role::create(['name' => 'editable-role', 'guard_name' => 'web']);

        $this->actingAs($super)->get("/roles/{$role->id}/edit")->assertOk();
    }

    // ─── Role delete ─────────────────────────────────────────────────────────

    public function test_super_admin_can_delete_role(): void
    {
        $super = $this->createSuperAdmin();
        $this->seedPermissionsAndRoles();

        $role = Role::create(['name' => 'deletable-role', 'guard_name' => 'web']);

        $this->actingAs($super)
            ->delete("/roles/{$role->id}")
            ->assertRedirect('/roles');

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_staff_cannot_delete_role(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');
        $this->seedPermissionsAndRoles();

        $role = Role::create(['name' => 'protected-role', 'guard_name' => 'web']);

        $this->actingAs($staff)
            ->delete("/roles/{$role->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
