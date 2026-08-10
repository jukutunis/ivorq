<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class HousekeepingPolicyTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeRoom(array $property): Room
    {
        static $seq = 0;
        return Room::create([
            'property_id'        => $property['id'],
            'room_number'        => (string) (100 + ++$seq),
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);
    }

    // ── RoomPolicy ────────────────────────────────────────────────────────────

    public function test_room_policy_property_admin_can_view_own_room(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->seedPermissionsAndRoles();
        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = $this->makeRoom($property->toArray());

        $this->assertTrue(Gate::inspect('view', $room)->allowed());
    }

    public function test_room_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();

        // Room belongs to property A
        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room = $this->makeRoom($propertyA->toArray());

        // User B tries to view it
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $room)->denied());
    }

    public function test_room_policy_super_admin_can_view_any_property_room(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedPermissionsAndRoles();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room = $this->makeRoom($propertyA->toArray());

        $this->actingAs($superAdmin);
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $this->assertTrue(Gate::inspect('view', $room)->allowed());
    }

    public function test_room_policy_staff_cannot_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPermissionsAndRoles();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', Room::class)->denied());
    }

    public function test_room_policy_staff_can_view_any(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPermissionsAndRoles();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('viewAny', Room::class)->allowed());
    }

    public function test_room_policy_property_admin_can_delete(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->seedPermissionsAndRoles();
        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = $this->makeRoom($property->toArray());

        $this->assertTrue(Gate::inspect('delete', $room)->allowed());
    }

    public function test_room_policy_staff_cannot_delete(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPermissionsAndRoles();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = $this->makeRoom($property->toArray());

        $this->assertTrue(Gate::inspect('delete', $room)->denied());
    }

    public function test_room_policy_change_status_requires_edit_permission(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $manager  = $this->createUser($property, 'general-manager');

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($property->id);

        $room = $this->makeRoom($property->toArray());

        $this->actingAs($staff);
        $this->assertTrue(Gate::inspect('changeStatus', $room)->denied());

        $this->actingAs($manager);
        $this->assertTrue(Gate::inspect('changeStatus', $room)->allowed());
    }

    // ── CleaningTaskPolicy ────────────────────────────────────────────────────

    public function test_task_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $task = CleaningTask::create([
            'property_id' => $propertyA->id,
            'task_code'   => 'TSK-CROSS',
            'title'       => 'Cross-property task',
            'task_type'   => 'custom',
            'status'      => 'pending',
            'priority'    => 3,
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $task)->denied());
    }

    public function test_task_policy_assign_requires_assign_permission(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $manager  = $this->createUser($property, 'general-manager');

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($property->id);

        $task = CleaningTask::create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-ASSIGN',
            'title'       => 'Assign test',
            'task_type'   => 'custom',
            'status'      => 'pending',
            'priority'    => 3,
        ]);

        $this->actingAs($staff);
        $this->assertTrue(Gate::inspect('assign', $task)->denied());

        $this->actingAs($manager);
        $this->assertTrue(Gate::inspect('assign', $task)->allowed());
    }

    // ── RoomInspectionPolicy ──────────────────────────────────────────────────

    public function test_inspection_policy_create_requires_inspect_permission(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $admin    = $this->createPropertyAdmin($property);

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($property->id);

        $this->actingAs($staff);
        $this->assertTrue(Gate::inspect('create', RoomInspection::class)->denied());

        $this->actingAs($admin);
        $this->assertTrue(Gate::inspect('create', RoomInspection::class)->allowed());
    }

    public function test_inspection_policy_conduct_requires_inspect_permission_and_isolation(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB03']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();

        // Room and inspection in property A
        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room = $this->makeRoom($propertyA->toArray());
        $inspection = RoomInspection::create([
            'property_id'     => $propertyA->id,
            'room_id'         => $room->id,
            'inspection_type' => 'routine',
            'status'          => 'pending',
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('conduct', $inspection)->denied());
    }

    // ── ChecklistPolicy ───────────────────────────────────────────────────────

    public function test_checklist_policy_staff_can_view_but_not_edit(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($property->id);

        $checklist = CleaningChecklist::create([
            'property_id' => $property->id,
            'name'        => 'Policy Test Checklist',
            'is_active'   => true,
        ]);

        $this->actingAs($staff);

        $this->assertTrue(Gate::inspect('view', $checklist)->allowed());
        $this->assertTrue(Gate::inspect('update', $checklist)->denied());
        $this->assertTrue(Gate::inspect('delete', $checklist)->denied());
    }

    // ── TaskAssignmentPolicy ──────────────────────────────────────────────────

    public function test_task_assignment_policy_denies_cross_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $propertyB  = $this->createProperty($company, ['code' => 'PB04']);
        $adminA     = $this->createPropertyAdmin($propertyA);
        $adminB     = $this->createPropertyAdmin($propertyB);
        $department = $this->createDepartment($propertyA);

        $this->seedPermissionsAndRoles();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room = $this->makeRoom($propertyA->toArray());
        $task = CleaningTask::create([
            'property_id' => $propertyA->id,
            'room_id'     => $room->id,
            'task_code'   => 'TSK-APol',
            'title'       => 'Policy Task',
            'task_type'   => 'custom',
            'status'      => 'pending',
            'priority'    => 3,
        ]);
        $assignment = $this->dispatchHousekeepingTask($task, $adminA, $department);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $assignment)->denied());
        $this->assertTrue(Gate::inspect('delete', $assignment)->denied());
    }
}
