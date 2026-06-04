<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Activity\Models\ActivityLog;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\Housekeeping\Events\CleaningTaskCreated;
use Modules\Operations\Housekeeping\Events\RoomCreated;
use Modules\Operations\Housekeeping\Events\RoomStatusChanged;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Models\RoomStatusHistory;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class HousekeepingPhase5Test extends \Tests\TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ── Boot sanity ───────────────────────────────────────────────────────────

    public function test_housekeeping_service_provider_boots_without_error(): void
    {
        // If the app booted successfully, the provider registered correctly.
        $this->assertTrue(true);
    }

    // ── RoomObserver → audit_logs ─────────────────────────────────────────────

    public function test_creating_a_room_writes_audit_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '101',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Room::class,
            'auditable_id'   => $room->id,
            'event'          => 'created',
        ]);
    }

    public function test_updating_a_room_writes_audit_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '102',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $room->update(['room_name' => 'Garden View']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Room::class,
            'auditable_id'   => $room->id,
            'event'          => 'updated',
        ]);
    }

    // ── RoomCreated → RecordRoomHistory + LogHousekeepingActivity ─────────────

    public function test_room_created_event_writes_room_status_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '201',
            'room_type'          => 'villa',
            'cleanliness_status' => 'dirty',
        ]);

        event(new RoomCreated($room));

        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'cleanliness',
            'from_status'  => null,
            'to_status'    => 'dirty',
            'action'       => 'room_created',
        ]);
    }

    public function test_room_created_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '202',
            'room_type'          => 'suite',
            'cleanliness_status' => 'dirty',
        ]);

        event(new RoomCreated($room));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Room::class,
            'subject_id'   => $room->id,
        ]);
    }

    // ── RoomStatusChanged → RecordRoomHistory + LogHousekeepingActivity ───────

    public function test_cleanliness_status_changed_writes_correct_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '301',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        event(new RoomStatusChanged($room, 'cleanliness', 'dirty', 'clean', 'cleaned by housekeeper'));

        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'cleanliness',
            'from_status'  => 'dirty',
            'to_status'    => 'clean',
            'action'       => 'room_cleaned',
            'remarks'      => 'cleaned by housekeeper',
        ]);
    }

    public function test_occupancy_status_changed_writes_correct_history(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '302',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        event(new RoomStatusChanged($room, 'occupancy', null, 'occupied', null));

        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'occupancy',
            'from_status'  => null,
            'to_status'    => 'occupied',
            'action'       => 'room_occupied',
        ]);
    }

    public function test_room_status_changed_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '303',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $before = ActivityLog::where('subject_type', Room::class)->where('subject_id', $room->id)->count();

        event(new RoomStatusChanged($room, 'cleanliness', 'dirty', 'clean', null));

        $after = ActivityLog::where('subject_type', Room::class)->where('subject_id', $room->id)->count();
        $this->assertGreaterThan($before, $after);
    }

    // ── CleaningTaskCreated → RecordTaskHistory + LogHousekeepingActivity ─────

    public function test_cleaning_task_created_event_writes_activity_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task = CleaningTask::create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-001',
            'title'       => 'Checkout Clean Room 101',
            'task_type'   => 'checkout_cleaning',
            'status'      => 'pending',
            'priority'    => 3,
        ]);

        event(new CleaningTaskCreated($task));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => CleaningTask::class,
            'subject_id'   => $task->id,
        ]);
    }

    public function test_creating_a_cleaning_task_writes_audit_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $task = CleaningTask::create([
            'property_id' => $property->id,
            'task_code'   => 'TSK-002',
            'title'       => 'Deep Clean Villa A',
            'task_type'   => 'deep_cleaning',
            'status'      => 'pending',
            'priority'    => 2,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => CleaningTask::class,
            'auditable_id'   => $task->id,
            'event'          => 'created',
        ]);
    }

    // ── History action labels ─────────────────────────────────────────────────

    public function test_room_status_history_records_correct_action_labels(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '401',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $cases = [
            ['cleanliness', 'dirty',    'clean',     'room_cleaned'],
            ['cleanliness', 'clean',    'inspected', 'room_inspected'],
            ['cleanliness', 'clean',    'dirty',     'room_soiled'],
            ['cleanliness', 'inspected','dirty',     'room_soiled'],
            ['occupancy',   null,       'vacant',    'room_vacated'],
            ['occupancy',   'vacant',   'occupied',  'room_occupied'],
            ['occupancy',   'occupied', 'vacant',    'room_vacated'],
            ['occupancy',   'vacant',   'blocked',   'room_blocked'],
        ];

        foreach ($cases as [$field, $from, $to, $expectedAction]) {
            event(new RoomStatusChanged($room, $field, $from, $to, null));

            $this->assertDatabaseHas('room_status_histories', [
                'room_id'      => $room->id,
                'status_field' => $field,
                'to_status'    => $to,
                'action'       => $expectedAction,
            ]);
        }
    }
}
