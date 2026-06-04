<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Zoning\Enums\ZoneAssignmentStatusEnum;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Modules\Operations\Zoning\Models\ZoneHistory;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class ZoneAssignmentModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ─── Assign to active zone ──────────────────────────────────────────────

    public function test_assign_employee_to_active_zone_succeeds(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $employee = $this->createUser($property, 'staff');
        $dept     = $this->createDepartment($property);
        $zone     = $this->createActiveZone($property);

        $this->actingAs($admin)
            ->post("/operations/zones/{$zone->id}/assignments", [
                'user_id'       => $employee->id,
                'department_id' => $dept->id,
                'start_date'    => '2026-07-01',
                'end_date'      => '2026-07-31',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('zone_assignments', [
            'zone_id'       => $zone->id,
            'user_id'       => $employee->id,
            'property_id'   => $property->id,
            'status'        => ZoneAssignmentStatusEnum::Active->value,
        ]);
    }

    // ─── Assign to draft zone ───────────────────────────────────────────────

    public function test_assign_employee_to_draft_zone_fails(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $employee = $this->createUser($property, 'staff');
        $dept     = $this->createDepartment($property);
        $zone     = $this->createZone($property); // draft

        // Accept: application/json forces the ValidationException to render as 422
        // rather than a 302 redirect (web default).
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/operations/zones/{$zone->id}/assignments", [
                'user_id'       => $employee->id,
                'department_id' => $dept->id,
                'start_date'    => '2026-07-01',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('zone_assignments', [
            'zone_id' => $zone->id,
            'user_id' => $employee->id,
        ]);
    }

    // ─── Overlap validation ─────────────────────────────────────────────────

    public function test_overlap_validation_blocks_duplicate_assignment(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $employee = $this->createUser($property, 'staff');
        $dept     = $this->createDepartment($property);
        $zone     = $this->createActiveZone($property);

        // First assignment
        $this->createZoneAssignment($zone, $employee, $dept, [
            'start_date' => '2026-07-01',
            'end_date'   => '2026-07-31',
        ]);

        // Overlapping second assignment — force JSON to get 422 instead of 302 redirect
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/operations/zones/{$zone->id}/assignments", [
                'user_id'       => $employee->id,
                'department_id' => $dept->id,
                'start_date'    => '2026-07-15',
                'end_date'      => '2026-08-15',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('zone_assignments', 1);
    }

    // ─── End assignment ─────────────────────────────────────────────────────

    public function test_assignment_end_sets_inactive_and_today(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $employee   = $this->createUser($property, 'staff');
        $dept       = $this->createDepartment($property);
        $zone       = $this->createActiveZone($property);
        $assignment = $this->createZoneAssignment($zone, $employee, $dept);

        $this->actingAs($admin)
            ->post("/operations/zones/{$zone->id}/assignments/{$assignment->id}/end")
            ->assertRedirect();

        $this->assertDatabaseHas('zone_assignments', [
            'id'     => $assignment->id,
            'status' => ZoneAssignmentStatusEnum::Inactive->value,
        ]);

        $this->assertDatabaseHas('zone_histories', [
            'zone_id' => $zone->id,
            'action'  => 'assignment_ended',
        ]);
    }

    // ─── Reassign ───────────────────────────────────────────────────────────

    public function test_reassignment_ends_old_and_creates_new(): void
    {
        $company    = $this->createCompany();
        $property   = $this->createProperty($company);
        $admin      = $this->createPropertyAdmin($property);
        $employee   = $this->createUser($property, 'staff');
        $dept       = $this->createDepartment($property);
        $zone       = $this->createActiveZone($property);
        $assignment = $this->createZoneAssignment($zone, $employee, $dept, [
            'start_date' => '2026-07-01',
            'end_date'   => '2026-07-31',
        ]);

        $this->actingAs($admin)
            ->post("/operations/zones/{$zone->id}/assignments/{$assignment->id}/reassign", [
                'user_id'       => $employee->id,
                'department_id' => $dept->id,
                'start_date'    => '2026-08-01',
            ])
            ->assertRedirect();

        // Old assignment is now inactive
        $this->assertDatabaseHas('zone_assignments', [
            'id'     => $assignment->id,
            'status' => ZoneAssignmentStatusEnum::Inactive->value,
        ]);

        // New active assignment created (start_date omitted — PostgreSQL returns date as datetime string)
        $this->assertDatabaseHas('zone_assignments', [
            'zone_id' => $zone->id,
            'user_id' => $employee->id,
            'status'  => ZoneAssignmentStatusEnum::Active->value,
        ]);
        $this->assertDatabaseCount('zone_assignments', 2);

        // History records both events
        $this->assertDatabaseHas('zone_histories', [
            'zone_id' => $zone->id,
            'action'  => 'employee_assigned',
        ]);
        $this->assertDatabaseHas('zone_histories', [
            'zone_id' => $zone->id,
            'action'  => 'employee_reassigned',
        ]);
    }

    // ─── Property isolation ─────────────────────────────────────────────────

    public function test_cross_property_assignment_access_denied(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB10']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $employeeB = $this->createUser($propertyB, 'staff');
        $deptB     = $this->createDepartment($propertyB);
        $zoneA     = $this->createActiveZone($propertyA);

        // User B cannot post assignments to Zone A.
        // BelongsToProperty global scope hides Zone A from property B user → 404.
        $this->actingAs($adminB)
            ->post("/operations/zones/{$zoneA->id}/assignments", [
                'user_id'       => $employeeB->id,
                'department_id' => $deptB->id,
                'start_date'    => '2026-07-01',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('zone_assignments', [
            'zone_id' => $zoneA->id,
        ]);
    }

    // ─── Audit trail ────────────────────────────────────────────────────────

    public function test_creating_assignment_generates_audit_and_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);
        $employee = $this->createUser($property, 'staff');
        $dept     = $this->createDepartment($property);
        $zone     = $this->createActiveZone($property);

        $this->actingAs($admin)
            ->post("/operations/zones/{$zone->id}/assignments", [
                'user_id'       => $employee->id,
                'department_id' => $dept->id,
                'start_date'    => '2026-07-01',
                'end_date'      => '2026-07-31',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ZoneAssignment::class,
            'event'          => 'created',
        ]);

        $this->assertDatabaseHas('zone_histories', [
            'zone_id' => $zone->id,
            'action'  => 'employee_assigned',
        ]);
    }
}
