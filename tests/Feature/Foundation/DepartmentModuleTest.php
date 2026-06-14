<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\Position;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class DepartmentModuleTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── Authentication ────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get('/departments')->assertRedirect('/login');
    }

    // ─── Authorisation ─────────────────────────────────────────────────────

    public function test_staff_without_view_permission_gets_403(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/departments')->assertForbidden();
    }

    public function test_property_admin_can_list_departments(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/departments')->assertOk();
    }

    // ─── Property Isolation ────────────────────────────────────────────────

    public function test_department_belongs_to_correct_property(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $dept    = $this->createDepartment($prop);

        $this->assertDatabaseHas('departments', [
            'id'          => $dept->id,
            'property_id' => $prop->id,
        ]);
    }

    public function test_user_cannot_view_department_from_another_property(): void
    {
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $propB    = $this->createProperty($company, ['code' => 'PB99']);
        $adminA   = $this->createPropertyAdmin($propA);
        $deptB    = $this->createDepartment($propB, ['code' => 'HKB']);

        // The global scope will restrict the query to propA, so deptB is not visible
        $this->actingAs($adminA)
            ->get("/departments/{$deptB->id}")
            ->assertNotFound();
    }

    public function test_department_global_scope_filters_by_property(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'PB01']);

        $deptA = $this->createDepartment($propA, ['code' => 'HKA']);
        $deptB = $this->createDepartment($propB, ['code' => 'HKB']);

        $adminA = $this->createPropertyAdmin($propA);

        // actingAs sets the user; SetPermissionTeamIdMiddleware sets Spatie team;
        // BelongsToProperty global scope adds WHERE property_id = propA.id
        $response = $this->actingAs($adminA)->get('/departments');
        $response->assertOk();

        // Directly verify the scope on the model
        setPermissionsTeamId($propA->id);
        $visible = Department::all()->pluck('id')->toArray();

        $this->assertContains($deptA->id, $visible);
        $this->assertNotContains($deptB->id, $visible);
    }

    // ─── CRUD ──────────────────────────────────────────────────────────────

    public function test_admin_can_create_department(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/departments', [
            'name' => 'Housekeeping',
            'code' => 'HK',
        ])->assertRedirect();

        $this->assertDatabaseHas('departments', [
            'property_id' => $prop->id,
            'code'        => 'HK',
        ]);
    }

    public function test_department_code_must_be_unique_per_property(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->createDepartment($prop, ['code' => 'DUPE']);

        $this->actingAs($admin)->post('/departments', [
            'name' => 'Duplicate',
            'code' => 'DUPE',
        ])->assertSessionHasErrors('code');
    }

    public function test_admin_can_update_department(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin)
            ->put("/departments/{$dept->id}", ['name' => 'Engineering Updated'])
            ->assertRedirect();

        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'name' => 'Engineering Updated']);
    }

    public function test_admin_can_soft_delete_department(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin)
            ->delete("/departments/{$dept->id}")
            ->assertRedirect('/departments');

        $this->assertSoftDeleted('departments', ['id' => $dept->id]);
    }

    // ─── Positions ─────────────────────────────────────────────────────────

    public function test_super_admin_can_create_global_position(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->post('/positions', [
            'name'          => 'Housekeeper',
            'code'          => 'HKR',
            'level'         => 2,
        ])->assertRedirect();

        $this->assertDatabaseHas('positions', [
            'code'          => 'HKR',
        ]);
    }

    // ─── Audit trail ───────────────────────────────────────────────────────

    public function test_creating_department_generates_audit_log(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/departments', [
            'name' => 'Audited Dept',
            'code' => 'AUD',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Department::class,
            'event'          => 'created',
        ]);
    }

    public function test_updating_department_generates_audit_log(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin)->put("/departments/{$dept->id}", ['name' => 'Changed']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Department::class,
            'auditable_id'   => $dept->id,
            'event'          => 'updated',
        ]);
    }

    // ─── Department show ───────────────────────────────────────────────────────

    public function test_admin_can_view_department_show(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin)->get("/departments/{$dept->id}")->assertOk();
    }

    public function test_staff_cannot_create_department(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->post('/departments', [
            'name' => 'Unauthorized Dept',
            'code' => 'UD1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('departments', ['code' => 'UD1']);
    }

    // ─── Positions: additional coverage ────────────────────────────────────────

    public function test_super_admin_can_update_global_position(): void
    {
        $admin    = $this->createSuperAdmin();
        $position = $this->createPosition();

        $this->actingAs($admin)
            ->put("/positions/{$position->id}", ['name' => 'Senior Housekeeper'])
            ->assertRedirect();

        $this->assertDatabaseHas('positions', ['id' => $position->id, 'name' => 'Senior Housekeeper']);
    }

    public function test_super_admin_can_delete_global_position(): void
    {
        $admin    = $this->createSuperAdmin();
        $position = $this->createPosition();

        $this->actingAs($admin)
            ->delete("/positions/{$position->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
    }

    public function test_creating_position_generates_audit_log(): void
    {
        $admin   = $this->createSuperAdmin();

        $this->actingAs($admin)->post('/positions', [
            'name'          => 'Audit Position',
            'code'          => 'AP1',
            'level'         => 2,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Position::class,
            'event'          => 'created',
        ]);
    }
}
