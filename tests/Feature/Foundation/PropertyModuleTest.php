<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class PropertyModuleTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── Authentication ────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get('/properties')->assertRedirect('/login');
    }

    // ─── Authorisation ─────────────────────────────────────────────────────

    public function test_user_without_view_permission_gets_403_on_index(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff)->get('/properties')->assertForbidden();
    }

    public function test_user_with_view_permission_can_list_properties(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/properties')->assertOk();
    }

    public function test_super_admin_can_list_properties(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)->get('/properties')->assertOk();
    }

    public function test_user_without_create_permission_cannot_access_create_form(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/properties/create')->assertForbidden();
    }

    // ─── CRUD ──────────────────────────────────────────────────────────────

    public function test_super_admin_can_store_property(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();

        $response = $this->actingAs($super)->post('/properties', [
            'company_id' => $company->id,
            'name'       => 'Grand Ballroom Hotel',
            'code'       => 'GBH',
            'timezone'   => 'Asia/Jakarta',
            'currency'   => 'IDR',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('properties', ['code' => 'GBH']);
    }

    public function test_property_code_must_be_unique(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();
        $this->createProperty($company, ['code' => 'DUP01']);

        $this->actingAs($super)->post('/properties', [
            'company_id' => $company->id,
            'name'       => 'Duplicate',
            'code'       => 'DUP01',
        ])->assertSessionHasErrors('code');
    }

    public function test_admin_can_update_property(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)
            ->put("/properties/{$prop->id}", ['name' => 'Updated Name'])
            ->assertRedirect();

        $this->assertDatabaseHas('properties', ['id' => $prop->id, 'name' => 'Updated Name']);
    }

    public function test_user_without_edit_permission_cannot_update_property(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)
            ->put("/properties/{$prop->id}", ['name' => 'Hack'])
            ->assertForbidden();
    }

    public function test_admin_can_delete_property(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)
            ->delete("/properties/{$prop->id}")
            ->assertRedirect('/properties');

        $this->assertSoftDeleted('properties', ['id' => $prop->id]);
    }

    public function test_user_without_delete_permission_cannot_delete_property(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)
            ->delete("/properties/{$prop->id}")
            ->assertForbidden();
    }

    // ─── Audit trail ───────────────────────────────────────────────────────

    public function test_creating_property_generates_audit_log(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();

        $this->actingAs($super)->post('/properties', [
            'company_id' => $company->id,
            'name'       => 'Audit Trail Hotel',
            'code'       => 'ATH01',
            'timezone'   => 'UTC',
            'currency'   => 'USD',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Property::class,
            'event'          => 'created',
        ]);
    }

    public function test_updating_property_generates_audit_log(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->put("/properties/{$prop->id}", ['name' => 'New Name']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Property::class,
            'auditable_id'   => $prop->id,
            'event'          => 'updated',
        ]);
    }

    // ─── Company ───────────────────────────────────────────────────────────

    public function test_super_admin_can_create_company(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)->post('/companies', [
            'name' => 'IVORQ Hotels Group',
        ])->assertRedirect();

        $this->assertDatabaseHas('companies', ['name' => 'IVORQ Hotels Group']);
    }

    public function test_creating_company_generates_audit_log(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)->post('/companies', ['name' => 'Audited Corp']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Company::class,
            'event'          => 'created',
        ]);
    }

    // ─── Company: additional coverage ──────────────────────────────────────────

    public function test_super_admin_can_list_companies(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)->get('/companies')->assertOk();
    }

    public function test_staff_cannot_list_companies(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/companies')->assertForbidden();
    }

    public function test_super_admin_can_show_company(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();

        $this->actingAs($super)->get("/companies/{$company->id}")->assertOk();
    }

    public function test_super_admin_can_update_company(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();

        $this->actingAs($super)
            ->put("/companies/{$company->id}", ['name' => 'Renamed Corp'])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Renamed Corp']);
    }

    public function test_super_admin_can_delete_company(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();

        $this->actingAs($super)
            ->delete("/companies/{$company->id}")
            ->assertRedirect('/companies');

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    // ─── Property: additional coverage ─────────────────────────────────────────

    public function test_admin_can_view_property_show(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get("/properties/{$prop->id}")->assertOk();
    }

    public function test_admin_can_access_property_edit_form(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get("/properties/{$prop->id}/edit")->assertOk();
    }

    public function test_storing_property_requires_company_id(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)->post('/properties', [
            'name'     => 'No Company Hotel',
            'code'     => 'NC01',
            'timezone' => 'UTC',
            'currency' => 'USD',
        ])->assertSessionHasErrors('company_id');
    }
}
