<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class AuditModuleTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── Observer fires ─────────────────────────────────────────────────────

    public function test_observer_fires_on_model_created(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/departments', ['name' => 'HK', 'code' => 'HK']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Department::class,
            'event'          => 'created',
        ]);
    }

    public function test_observer_fires_on_model_updated(): void
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

    public function test_observer_fires_on_model_deleted(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin)->delete("/departments/{$dept->id}");

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Department::class,
            'auditable_id'   => $dept->id,
            'event'          => 'deleted',
        ]);
    }

    // ─── Immutability ───────────────────────────────────────────────────────

    public function test_audit_log_has_no_updated_at_column(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $dept    = $this->createDepartment($prop);

        $log = AuditLog::where('auditable_type', Department::class)->first();
        $this->assertNotNull($log);

        // Confirm no updated_at column on the model (timestamps = false)
        $this->assertFalse(property_exists($log, 'updated_at'));
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }

    public function test_audit_log_is_not_soft_deleted(): void
    {
        // AuditLog does not use SoftDeletes — deleted_at column doesn't exist
        $log = new AuditLog();
        $this->assertFalse(in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($log)
        ));
    }

    // ─── Security: Password scrubbing ───────────────────────────────────────

    public function test_password_is_not_stored_in_audit_new_values(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/users', [
            'name'                  => 'Secret',
            'email'                 => 'secret2@test.com',
            'password'              => 'sensitive123',
            'password_confirmation' => 'sensitive123',
        ]);

        $log = AuditLog::where('auditable_type', User::class)
            ->where('event', 'created')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('password', $log->new_values ?? []);
    }

    // ─── Property scoping ───────────────────────────────────────────────────

    public function test_audit_log_records_correct_property_id(): void
    {
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $adminA   = $this->createPropertyAdmin($propA);

        $this->actingAs($adminA)->post('/departments', ['name' => 'Scoped Dept', 'code' => 'SCD']);

        $log = AuditLog::where('auditable_type', Department::class)->first();
        $this->assertEquals($propA->id, $log->property_id);
    }

    public function test_audit_log_records_authenticated_user_id(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/departments', ['name' => 'UserTracked', 'code' => 'UTD']);

        $log = AuditLog::where('auditable_type', Department::class)->first();
        $this->assertEquals($admin->id, $log->user_id);
    }

    public function test_audit_log_index_requires_view_permission(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/audit')->assertForbidden();
    }

    public function test_admin_can_view_audit_log_index(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/audit')->assertOk();
    }

    // ─── AuditService direct write ───────────────────────────────────────────

    public function test_audit_service_writes_log_directly(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin);

        $dept = $this->createDepartment($prop);

        app(AuditService::class)->log(
            event: 'custom',
            model: $dept,
            newValues: ['note' => 'manual entry'],
            tags: ['manual']
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Department::class,
            'auditable_id'   => $dept->id,
            'event'          => 'custom',
        ]);
    }

    // ─── Multiple models observed ─────────────────────────────────────────

    public function test_property_model_is_observed(): void
    {
        $super   = $this->createSuperAdmin();
        $company = $this->createCompany();

        $this->actingAs($super)->post('/properties', [
            'company_id' => $company->id,
            'name'       => 'Observed Property',
            'code'       => 'OBS01',
            'timezone'   => 'UTC',
            'currency'   => 'USD',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Property::class,
            'event'          => 'created',
        ]);
    }

    public function test_user_model_is_observed(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/users', [
            'name'                  => 'Observed User',
            'email'                 => 'observed@test.com',
            'password'              => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'event'          => 'created',
        ]);
    }

    // ─── Show route ──────────────────────────────────────────────────────────

    public function test_admin_can_view_audit_log_show(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        // createDepartment triggers AuditObserver and writes a log row
        $this->createDepartment($prop);

        $log = AuditLog::where('auditable_type', Department::class)->first();
        $this->assertNotNull($log);

        $this->actingAs($admin)->get("/audit/{$log->id}")->assertOk();
    }

    public function test_staff_cannot_view_audit_log(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/audit')->assertForbidden();
    }

    // ─── old_values capture on update ────────────────────────────────────────

    public function test_audit_log_captures_old_values_on_update(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop, ['name' => 'Original Name']);

        $this->actingAs($admin)->put("/departments/{$dept->id}", ['name' => 'New Name']);

        $log = AuditLog::where('auditable_type', Department::class)
            ->where('auditable_id', $dept->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('name', $log->old_values ?? []);
        $this->assertEquals('Original Name', $log->old_values['name']);
    }
}
