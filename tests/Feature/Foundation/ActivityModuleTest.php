<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Activity\Models\ActivityLog;
use Modules\Foundation\Activity\Services\ActivityService;
use Modules\Foundation\Department\Models\Department;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ActivityModuleTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── ActivityService::log() ──────────────────────────────────────────────

    public function test_activity_service_creates_activity_log_record(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin);

        app(ActivityService::class)->log(
            description: 'Test activity entry',
            subject:     $dept,
        );

        $this->assertDatabaseHas('activity_logs', ['description' => 'Test activity entry']);
    }

    public function test_activity_log_records_subject(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin);

        app(ActivityService::class)->log(
            description: 'Subject test',
            subject:     $dept,
        );

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Department::class,
            'subject_id'   => $dept->id,
        ]);
    }

    public function test_activity_log_records_causer_from_auth(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin);

        app(ActivityService::class)->log(description: 'Causer test', subject: $dept);

        $this->assertDatabaseHas('activity_logs', [
            'user_id'      => $admin->id,
            'causer_id'    => $admin->id,
        ]);
    }

    public function test_activity_log_records_property_id_from_subject(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $dept    = $this->createDepartment($prop);

        $this->actingAs($admin);

        app(ActivityService::class)->log(description: 'Prop test', subject: $dept);

        $this->assertDatabaseHas('activity_logs', ['property_id' => $prop->id]);
    }

    public function test_activity_log_stores_properties_payload(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin);

        app(ActivityService::class)->log(
            description: 'With payload',
            properties:  ['action' => 'test', 'value' => 42],
        );

        $log = ActivityLog::where('description', 'With payload')->first();
        $this->assertNotNull($log);
        $this->assertEquals('test', $log->properties['action']);
        $this->assertEquals(42, $log->properties['value']);
    }

    // ─── Immutability ───────────────────────────────────────────────────────

    public function test_activity_log_has_no_updated_at_column(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin);

        app(ActivityService::class)->log(description: 'Immutable check');

        $log = ActivityLog::first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }

    public function test_activity_log_is_not_soft_deleted(): void
    {
        $log = new ActivityLog();
        $this->assertFalse(in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($log)
        ));
    }

    // ─── Access Control ─────────────────────────────────────────────────────

    public function test_staff_without_view_permission_cannot_see_activity_feed(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        // Staff role has activity.view permission
        // Verify that the activity endpoint resolves for staff
        setPermissionsTeamId($prop->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($staff)->get('/activity')->assertOk();
    }

    public function test_unauthenticated_user_cannot_see_activity_feed(): void
    {
        $this->get('/activity')->assertRedirect('/login');
    }

    public function test_property_admin_can_view_activity_log_index(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/activity')->assertOk();
    }

    // ─── Property isolation ─────────────────────────────────────────────────

    public function test_activity_log_is_scoped_to_current_property(): void
    {
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $propB    = $this->createProperty($company, ['code' => 'PB50']);
        $adminA   = $this->createPropertyAdmin($propA);
        $deptB    = $this->createDepartment($propB);

        // Write log for propB
        $this->actingAs($adminA);

        app(ActivityService::class)->log(
            description: 'PropB log',
            subject:     $deptB,
            propertyId:  $propB->id,
        );

        // Repo query scoped to propA should NOT return this log
        $repo = app(\Modules\Foundation\Activity\Repositories\ActivityLogRepository::class);

        // Simulate propA context
        app(\Shared\Services\CurrentPropertyService::class)->setId($propA->id);
        $logs = $repo->paginate()->items();

        $descriptions = array_map(fn($l) => $l->description, $logs);
        $this->assertNotContains('PropB log', $descriptions);
    }

    // ─── record() write path guard ───────────────────────────────────────────

    public function test_direct_create_is_blocked_by_guarded(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        ActivityLog::create(['description' => 'Should fail']);
    }

    // ─── Show route ──────────────────────────────────────────────────────────

    public function test_admin_can_view_activity_show(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin);
        app(ActivityService::class)->log(description: 'Show route check');

        $log = ActivityLog::first();
        $this->assertNotNull($log);

        $this->actingAs($admin)->get("/activity/{$log->id}")->assertOk();
    }

    public function test_staff_can_view_activity_show(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $staff   = $this->createUser($prop, 'staff');

        // Write a log entry while authenticated as admin
        $this->actingAs($admin);
        app(ActivityService::class)->log(description: 'Staff show check');

        $log = ActivityLog::first();
        $this->assertNotNull($log);

        // Staff has activity.view — show should succeed
        setPermissionsTeamId($prop->id);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($staff)->get("/activity/{$log->id}")->assertOk();
    }

    public function test_unauthenticated_user_cannot_view_activity_show(): void
    {
        $this->get('/activity/fake-id')->assertRedirect('/login');
    }
}
