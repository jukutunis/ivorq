<?php

namespace Tests\Feature\Foundation\Department;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\DepartmentSupervisor;
use Modules\Foundation\Department\Services\DepartmentSupervisorService;
use Modules\Foundation\User\Models\User;
use Spatie\Activitylog\Models\Activity;

class DepartmentSupervisoryAuthorityFoundationTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Foundation\Concerns\CreatesFoundationData;

    protected Property $property;
    protected Property $otherProperty;
    protected User $manager; // Has department.supervisors.manage
    protected User $clarifyOnlyUser; // Has logbook.clarify only
    protected User $regularUser; // No management permissions
    protected User $supervisorUser; // The target supervisor
    protected User $otherPropertyUser; // Belongs to other Property
    protected Department $departmentA;
    protected Department $departmentB;
    protected Department $otherPropertyDepartment;
    protected DepartmentSupervisorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        
        $otherCompany = $this->createCompany();
        $this->otherProperty = $this->createProperty($otherCompany, ['code' => 'OTH']);

        // Seed Spatie roles/permissions
        $this->seedPermissionsAndRoles();

        // Create users
        $this->manager = $this->createUser($this->property, 'property-admin');
        
        $this->regularUser = $this->createUser($this->property, 'staff');
        
        $this->clarifyOnlyUser = $this->createUser($this->property, 'staff');
        $this->clarifyOnlyUser->givePermissionTo('logbook.clarify');

        $this->supervisorUser = $this->createUser($this->property, 'supervisor');
        $this->otherPropertyUser = $this->createUser($this->otherProperty, 'supervisor');

        // Create departments
        $this->departmentA = $this->createDepartment($this->property, ['name' => 'Department A', 'code' => 'DEPTA']);
        $this->departmentB = $this->createDepartment($this->property, ['name' => 'Department B', 'code' => 'DEPTB']);
        $this->otherPropertyDepartment = $this->createDepartment($this->otherProperty, ['name' => 'Other Dept', 'code' => 'OTHDEPT']);

        $this->service = app(DepartmentSupervisorService::class);

        // Resolve active property context
        session([
            'current_property_id' => $this->property->id,
            'active_property_id'  => $this->property->id,
            'active_company_id'   => $this->property->company_id,
        ]);
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
    }

    /**
     * 1. Authorized assignment-management actor creates same-Property active assignment.
     * 2. Created assignment is active.
     */
    public function test_authorized_actor_can_create_active_supervisor_assignment()
    {
        $this->actingAs($this->manager);

        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);

        $this->assertInstanceOf(DepartmentSupervisor::class, $assignment);
        $this->assertTrue($assignment->is_active);
        $this->assertEquals($this->departmentA->id, $assignment->department_id);
        $this->assertEquals($this->supervisorUser->id, $assignment->user_id);
        
        $this->assertTrue($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
    }

    /**
     * 3. Authorized actor deactivates active assignment.
     */
    public function test_authorized_actor_can_deactivate_active_assignment()
    {
        $this->actingAs($this->manager);

        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);

        $updated = $this->service->deactivateSupervisor($assignment->id);

        $this->assertFalse($updated->is_active);
        $this->assertFalse($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
    }

    /**
     * 4. Authorized actor reactivates inactive assignment.
     */
    public function test_authorized_actor_can_reactivate_inactive_assignment()
    {
        $this->actingAs($this->manager);

        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
        $this->service->deactivateSupervisor($assignment->id);

        $reactivated = $this->service->reactivateSupervisor($assignment->id);

        $this->assertTrue($reactivated->is_active);
        $this->assertTrue($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
    }

    /**
     * 5. Self-assignment denied.
     */
    public function test_self_assignment_is_denied()
    {
        // Give manager role to supervisorUser but try to assign themselves
        $this->supervisorUser->givePermissionTo('department.supervisors.manage');
        $this->actingAs($this->supervisorUser);

        $this->expectException(AuthorizationException::class);

        $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
    }

    /**
     * 6. Self-deactivation denied.
     */
    public function test_self_deactivation_is_denied()
    {
        $this->actingAs($this->manager);
        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);

        // Make the supervisorUser an assignment-manager as well
        $this->supervisorUser->givePermissionTo('department.supervisors.manage');
        
        $this->actingAs($this->supervisorUser);
        $this->expectException(AuthorizationException::class);

        $this->service->deactivateSupervisor($assignment->id);
    }

    /**
     * 7. Self-reactivation denied.
     */
    public function test_self_reactivation_is_denied()
    {
        $this->actingAs($this->manager);
        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
        $this->service->deactivateSupervisor($assignment->id);

        // Make the supervisorUser an assignment-manager as well
        $this->supervisorUser->givePermissionTo('department.supervisors.manage');

        $this->actingAs($this->supervisorUser);
        $this->expectException(AuthorizationException::class);

        $this->service->reactivateSupervisor($assignment->id);
    }

    /**
     * 8. Caller without department.supervisors.manage denied.
     */
    public function test_caller_without_management_permission_is_denied()
    {
        $this->actingAs($this->regularUser);

        $this->expectException(AuthorizationException::class);

        $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
    }

    /**
     * 9. Cross-property Department denied.
     */
    public function test_cross_property_department_assignment_is_denied()
    {
        $this->actingAs($this->manager);

        $this->expectException(ValidationException::class);

        // Try to assign within active property but targeting other property's department
        $this->service->assignSupervisor($this->otherPropertyDepartment->id, $this->supervisorUser->id);
    }

    /**
     * 10. Cross-property target supervisor user denied.
     */
    public function test_cross_property_target_supervisor_user_is_denied()
    {
        $this->actingAs($this->manager);

        $this->expectException(ValidationException::class);

        // Try to assign other property's user to active property department
        $this->service->assignSupervisor($this->departmentA->id, $this->otherPropertyUser->id);
    }

    /**
     * 11. Duplicate active same-user/same-Department denied.
     */
    public function test_duplicate_active_assignment_is_denied()
    {
        $this->actingAs($this->manager);

        $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);

        $this->expectException(ValidationException::class);

        // Attempting to assign again while first is still active
        $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
    }

    /**
     * 12. Multiple active supervisors for the same Department allowed.
     */
    public function test_multiple_active_supervisors_for_same_department_are_allowed()
    {
        $this->actingAs($this->manager);
        
        $otherSupervisor = $this->createUser($this->property, 'supervisor');

        $assign1 = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
        $assign2 = $this->service->assignSupervisor($this->departmentA->id, $otherSupervisor->id);

        $this->assertTrue($assign1->is_active);
        $this->assertTrue($assign2->is_active);
        $this->assertTrue($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
        $this->assertTrue($this->service->isActiveSupervisorOf($this->departmentA->id, $otherSupervisor->id));
    }

    /**
     * 13. One user may supervise multiple same-Property Departments.
     */
    public function test_one_user_can_supervise_multiple_departments()
    {
        $this->actingAs($this->manager);

        $assign1 = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
        $assign2 = $this->service->assignSupervisor($this->departmentB->id, $this->supervisorUser->id);

        $this->assertTrue($assign1->is_active);
        $this->assertTrue($assign2->is_active);
        $this->assertTrue($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
        $this->assertTrue($this->service->isActiveSupervisorOf($this->departmentB->id, $this->supervisorUser->id));
    }

    /**
     * 14. users.department_id alone grants no supervisory authority.
     */
    public function test_user_department_membership_alone_grants_no_supervisory_authority()
    {
        // Set user's home department but create no supervisory assignment
        $this->supervisorUser->update(['department_id' => $this->departmentA->id]);

        $this->assertFalse($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
    }

    /**
     * 15. Inactive assignment returns false from isActiveSupervisorOf(...).
     */
    public function test_deactivated_assignment_is_not_active_supervisor()
    {
        $this->actingAs($this->manager);
        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
        
        $this->service->deactivateSupervisor($assignment->id);

        $this->assertFalse($this->service->isActiveSupervisorOf($this->departmentA->id, $this->supervisorUser->id));
    }

    /**
     * 16. logbook.clarify alone does not grant management authority.
     */
    public function test_clarify_permission_alone_cannot_manage_assignments()
    {
        $this->actingAs($this->clarifyOnlyUser);

        $this->expectException(AuthorizationException::class);

        $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
    }

    /**
     * 17. Mandatory audit evidence exists for create, deactivate, reactivate.
     */
    public function test_mandatory_audit_events_are_recorded()
    {
        $this->actingAs($this->manager);

        // 1. Assign
        $assignment = $this->service->assignSupervisor($this->departmentA->id, $this->supervisorUser->id);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => DepartmentSupervisor::class,
            'subject_id' => $assignment->id,
            'causer_id' => $this->manager->id,
            'description' => 'created',
        ]);

        $createLog = Activity::where('subject_id', $assignment->id)->where('description', 'created')->first();
        $this->assertNotNull($createLog);
        $this->assertEquals(User::class, $createLog->causer_type);
        $this->assertEquals($this->manager->id, $createLog->causer_id);
        $this->assertTrue($createLog->attribute_changes['attributes']['is_active']);

        // 2. Deactivate
        $this->service->deactivateSupervisor($assignment->id);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => DepartmentSupervisor::class,
            'subject_id' => $assignment->id,
            'causer_id' => $this->manager->id,
            'description' => 'updated',
        ]);
        
        $deactivateLog = Activity::where('subject_id', $assignment->id)->latest('id')->first();
        $this->assertEquals(User::class, $deactivateLog->causer_type);
        $this->assertEquals($this->manager->id, $deactivateLog->causer_id);
        $this->assertFalse($deactivateLog->attribute_changes['attributes']['is_active']);
        $this->assertTrue($deactivateLog->attribute_changes['old']['is_active']);

        // 3. Reactivate
        $this->service->reactivateSupervisor($assignment->id);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => DepartmentSupervisor::class,
            'subject_id' => $assignment->id,
            'causer_id' => $this->manager->id,
            'description' => 'updated',
        ]);
        
        $reactivateLog = Activity::where('subject_id', $assignment->id)->latest('id')->first();
        $this->assertEquals(User::class, $reactivateLog->causer_type);
        $this->assertEquals($this->manager->id, $reactivateLog->causer_id);
        $this->assertTrue($reactivateLog->attribute_changes['attributes']['is_active']);
        $this->assertFalse($reactivateLog->attribute_changes['old']['is_active']);
    }

    /**
     * 18. Database active uniqueness protects against a direct concurrent-equivalent duplicate insertion path.
     */
    public function test_database_enforces_active_uniqueness_constraint()
    {
        $ulid1 = (string) \Illuminate\Support\Str::ulid();
        $ulid2 = (string) \Illuminate\Support\Str::ulid();

        // Direct DB insertion bypasses service checks
        DB::table('department_supervisors')->insert([
            'id' => $ulid1,
            'department_id' => $this->departmentA->id,
            'user_id' => $this->supervisorUser->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('department_supervisors')->insert([
            'id' => $ulid2,
            'department_id' => $this->departmentA->id,
            'user_id' => $this->supervisorUser->id,
            'is_active' => true, // Attempt duplicate active insert
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 19. Focused rollback test proving atomic rollback of supervisor assignment and its audit evidence.
     */
    public function test_assignment_and_mandatory_audit_evidence_roll_back_together_on_outer_transaction_failure()
    {
        $actor = $this->createUser($this->property, 'property-admin');
        $targetUser = $this->createUser($this->property, 'supervisor');
        $department = $this->createDepartment($this->property, ['name' => 'Rollback Dept', 'code' => 'RBACKDEPT']);

        $this->actingAs($actor);

        session([
            'current_property_id' => $this->property->id,
            'active_property_id'  => $this->property->id,
            'active_company_id'   => $this->property->company_id,
        ]);
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $assignmentId = null;
        $exceptionThrown = false;

        try {
            DB::transaction(function () use ($department, $targetUser, &$assignmentId) {
                $assignment = $this->service->assignSupervisor($department->id, $targetUser->id);
                $assignmentId = $assignment->id;

                $this->assertDatabaseHas('department_supervisors', [
                    'id' => $assignmentId,
                    'department_id' => $department->id,
                    'user_id' => $targetUser->id,
                    'is_active' => true,
                ]);

                $this->assertDatabaseHas('activity_log', [
                    'subject_type' => DepartmentSupervisor::class,
                    'subject_id' => $assignmentId,
                    'description' => 'created',
                ]);

                throw new \RuntimeException('Deliberate transaction failure');
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Deliberate transaction failure') {
                $exceptionThrown = true;
            }
        }

        $this->assertTrue($exceptionThrown);

        $this->assertDatabaseMissing('department_supervisors', [
            'id' => $assignmentId,
        ]);

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => DepartmentSupervisor::class,
            'subject_id' => $assignmentId,
        ]);
    }
}
