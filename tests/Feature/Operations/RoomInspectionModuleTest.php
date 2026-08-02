<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Repositories\InspectionRepository;
use Modules\Operations\Housekeeping\Services\InspectionService;
use Modules\Operations\Housekeeping\Services\RoomService;
use Modules\Operations\Housekeeping\Services\HousekeepingCleaningInspectionReadinessLifecycleService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class RoomInspectionModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    private static int $roomSeq = 0;

    private function makeRoom(string $propertyId, string $cleanliness = 'dirty'): Room
    {
        return Room::create([
            'property_id'        => $propertyId,
            'room_number'        => (string) (100 + ++self::$roomSeq),
            'room_type'          => 'standard',
            'cleanliness_status' => $cleanliness,
            'readiness_state' => $cleanliness === 'clean' ? 'waiting_inspection' : 'waiting_cleaning',
        ]);
    }

    private function makeLifecycleInspection(
        string $propertyId,
        string $actorId,
        Room $room,
        ?string $confirmationReason = null,
    ): RoomInspection {
        $room->update([
            'cleanliness_status' => 'clean',
            'readiness_state' => 'waiting_inspection',
        ]);
        $task = CleaningTask::create([
            'property_id' => $propertyId,
            'room_id' => $room->id,
            'task_code' => 'IT-' . (++self::$roomSeq),
            'title' => 'Inspection source task',
            'task_type' => 'checkout_cleaning',
            'status' => 'completed',
            'priority' => 'normal',
            'completed_at' => now(),
            'completed_by' => $actorId,
            'notes' => 'Completed source task.',
        ]);
        $inspection = RoomInspection::create([
            'property_id' => $propertyId,
            'room_id' => $room->id,
            'cleaning_task_id' => $task->id,
            'inspection_type' => InspectionTypeEnum::PostCleaning->value,
            'status' => 'pending',
        ]);
        $inspection = app(InspectionService::class)->conduct($inspection->id);
        if ($confirmationReason !== null) {
            app(HousekeepingCleaningInspectionReadinessLifecycleService::class)->confirmInspectionPass(
                auth()->user(),
                $inspection->id,
                $confirmationReason,
                'password',
            );
        }

        return $inspection;
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_inspection_stores_in_database(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room       = $this->makeRoom($property->id);
        $inspection = app(InspectionService::class)->create([
            'property_id'     => $property->id,
            'room_id'         => $room->id,
            'inspection_type' => InspectionTypeEnum::Routine->value,
        ]);

        $this->assertInstanceOf(RoomInspection::class, $inspection);
        $this->assertDatabaseHas('room_inspections', [
            'property_id'     => $property->id,
            'room_id'         => $room->id,
            'inspection_type' => 'routine',
            'status'          => 'pending',
        ]);
    }

    // ── Conduct ───────────────────────────────────────────────────────────────

    public function test_conduct_inspection_transitions_status_to_in_progress(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room    = $this->makeRoom($property->id);
        $conducted = $this->makeLifecycleInspection($property->id, $admin->id, $room);

        $this->assertSame(InspectionStatusEnum::InProgress, $conducted->status);
        $this->assertDatabaseHas('room_inspections', [
            'id'     => $conducted->id,
            'status' => 'in_progress',
        ]);
    }

    // ── Pass ──────────────────────────────────────────────────────────────────

    public function test_pass_inspection_records_inspected_at(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $roomService       = app(RoomService::class);
        $inspectionService = app(InspectionService::class);

        $room = $this->makeRoom($property->id, 'clean');
        // Room must be clean for pass to succeed (pass transitions to inspected)
        // Manually set cleanliness via direct update to bypass observer
        $room->update(['cleanliness_status' => 'clean']);

        $inspection = $this->makeLifecycleInspection($property->id, $admin->id, $room, 'All clear');

        $passed = $inspectionService->pass($inspection->id, 'All clear');

        $this->assertSame(InspectionStatusEnum::Passed, $passed->status);
        $this->assertNotNull($passed->inspected_at);
        $this->assertSame('All clear', $passed->remarks);
    }

    // ── Fail ──────────────────────────────────────────────────────────────────

    public function test_fail_inspection_records_inspected_at_with_severity(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = $this->makeRoom($property->id, 'clean');
        $room->update(['cleanliness_status' => 'clean']);

        $service    = app(InspectionService::class);
        $inspection = $this->makeLifecycleInspection($property->id, $admin->id, $room);

        $failed = $service->fail($inspection->id, 'Bathroom not clean', InspectionSeverityEnum::Major);

        $this->assertSame(InspectionStatusEnum::Failed, $failed->status);
        $this->assertSame(InspectionSeverityEnum::Major, $failed->inspection_severity);
        $this->assertNotNull($failed->inspected_at);
    }

    // ── Pass → room inspected ─────────────────────────────────────────────────

    public function test_pass_inspection_transitions_room_cleanliness_to_inspected(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $roomService       = app(RoomService::class);
        $inspectionService = app(InspectionService::class);

        $room = $roomService->create(['property_id' => $property->id, 'room_number' => 'M501', 'room_type' => 'standard']);
        $roomService->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Clean);

        $inspection = $this->makeLifecycleInspection($property->id, $admin->id, $room, 'All quality standards satisfied');

        $inspectionService->pass($inspection->id, 'All quality standards satisfied');

        $room->refresh();
        $this->assertSame(RoomCleanlinessStatusEnum::Inspected, $room->cleanliness_status);
    }

    // ── Fail → room dirty ─────────────────────────────────────────────────────

    public function test_fail_inspection_transitions_room_cleanliness_to_dirty(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $roomService       = app(RoomService::class);
        $inspectionService = app(InspectionService::class);

        $room = $roomService->create(['property_id' => $property->id, 'room_number' => 'M502', 'room_type' => 'standard']);
        $roomService->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Clean);

        $inspection = $this->makeLifecycleInspection($property->id, $admin->id, $room);

        $inspectionService->fail($inspection->id, 'Failed quality check', InspectionSeverityEnum::Critical);

        $room->refresh();
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty, $room->cleanliness_status);
    }

    // ── Critical failure filter ───────────────────────────────────────────────

    public function test_failed_critical_inspections_can_be_filtered(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(InspectionRepository::class);

        $room = $this->makeRoom($property->id);

        $repo->create([
            'property_id'         => $property->id,
            'room_id'             => $room->id,
            'inspection_type'     => 'routine',
            'status'              => 'failed',
            'inspection_severity' => 'critical',
        ]);
        $repo->create([
            'property_id'         => $property->id,
            'room_id'             => $room->id,
            'inspection_type'     => 'routine',
            'status'              => 'passed',
            'inspection_severity' => null,
        ]);

        $critical = $repo->failedCritical();

        $this->assertCount(1, $critical);
        $this->assertSame(InspectionSeverityEnum::Critical, $critical->first()->inspection_severity);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_conduct_policy_denied(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB40']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $room = $this->makeRoom($propertyA->id);
        $inspection = RoomInspection::create([
            'property_id'     => $propertyA->id,
            'room_id'         => $room->id,
            'inspection_type' => 'routine',
            'status'          => 'pending',
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('conduct', $inspection)->denied());
        $this->assertTrue(Gate::inspect('view', $inspection)->denied());
    }
}
