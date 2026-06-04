<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Repositories\RoomRepository;
use Modules\Operations\Housekeeping\Services\RoomService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class RoomModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_room_stores_in_database(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = app(RoomService::class)->create([
            'property_id' => $property->id,
            'room_number' => '101',
            'room_type'   => 'standard',
        ]);

        $this->assertDatabaseHas('rooms', [
            'property_id'        => $property->id,
            'room_number'        => '101',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);
        $this->assertInstanceOf(Room::class, $room);
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty, $room->cleanliness_status);
        $this->assertNull($room->occupancy_status);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_room_changes_name_and_strips_status_fields(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '102', 'room_type' => 'deluxe']);

        $updated = $service->update($room->id, [
            'room_name'          => 'Pool View',
            'cleanliness_status' => 'clean',   // must be stripped
            'occupancy_status'   => 'occupied', // must be stripped
        ]);

        $this->assertSame('Pool View', $updated->room_name);
        $this->assertSame(RoomCleanlinessStatusEnum::Dirty, $updated->cleanliness_status);
        $this->assertNull($updated->occupancy_status);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_room_soft_deletes(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '103', 'room_type' => 'suite']);

        $this->assertTrue($service->delete($room->id));
        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_room_number_must_be_unique_per_property(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(RoomRepository::class);
        $repo->create(['property_id' => $property->id, 'room_number' => '201', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $repo->create(['property_id' => $property->id, 'room_number' => '201', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);
    }

    public function test_room_number_can_be_reused_across_properties(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $propertyB  = $this->createProperty($company, ['code' => 'PB10']);
        $adminA     = $this->createPropertyAdmin($propertyA);

        $this->actingAs($adminA);
        $repo = app(RoomRepository::class);

        $roomA = $repo->create(['property_id' => $propertyA->id, 'room_number' => '101', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);
        $roomB = $repo->create(['property_id' => $propertyB->id, 'room_number' => '101', 'room_type' => 'standard', 'cleanliness_status' => 'dirty']);

        $this->assertNotSame($roomA->id, $roomB->id);
        $this->assertDatabaseCount('rooms', 2);
    }

    // ── Cleanliness transitions ───────────────────────────────────────────────

    public function test_valid_cleanliness_transition_dirty_to_clean(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '301', 'room_type' => 'standard']);

        $updated = $service->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Clean, 'Cleaned');

        $this->assertSame(RoomCleanlinessStatusEnum::Clean, $updated->cleanliness_status);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'from_status'  => 'dirty',
            'to_status'    => 'clean',
            'action'       => 'room_cleaned',
        ]);
    }

    public function test_invalid_cleanliness_transition_dirty_to_inspected_throws(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '302', 'room_type' => 'standard']);

        $this->expectException(ValidationException::class);
        $service->changeCleanlinessStatus($room->id, RoomCleanlinessStatusEnum::Inspected);
    }

    // ── Occupancy transitions ─────────────────────────────────────────────────

    public function test_occupancy_transition_from_null_to_vacant_succeeds(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '401', 'room_type' => 'villa']);

        $this->assertNull($room->occupancy_status);

        $updated = $service->changeOccupancyStatus($room->id, RoomOccupancyStatusEnum::Vacant);

        $this->assertSame(RoomOccupancyStatusEnum::Vacant, $updated->occupancy_status);
        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'occupancy',
            'from_status'  => null,
            'to_status'    => 'vacant',
        ]);
    }

    public function test_occupancy_occupied_to_blocked_throws_validation_exception(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service = app(RoomService::class);
        $room    = $service->create(['property_id' => $property->id, 'room_number' => '402', 'room_type' => 'standard']);
        $service->changeOccupancyStatus($room->id, RoomOccupancyStatusEnum::Occupied);

        $this->expectException(ValidationException::class);
        $service->changeOccupancyStatus($room->id, RoomOccupancyStatusEnum::Blocked);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_room_policy_denies_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB11']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $room = Room::create([
            'property_id'        => $propertyA->id,
            'room_number'        => '501',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $room)->denied());
        $this->assertTrue(Gate::inspect('update', $room)->denied());
        $this->assertTrue(Gate::inspect('delete', $room)->denied());
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_create_room_writes_audit_log(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = Room::create([
            'property_id'        => $property->id,
            'room_number'        => '601',
            'room_type'          => 'standard',
            'cleanliness_status' => 'dirty',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Room::class,
            'auditable_id'   => $room->id,
            'event'          => 'created',
        ]);
    }

    // ── Status history ────────────────────────────────────────────────────────

    public function test_create_room_writes_status_history_via_service(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $room = app(RoomService::class)->create([
            'property_id' => $property->id,
            'room_number' => '701',
            'room_type'   => 'standard',
        ]);

        $this->assertDatabaseHas('room_status_histories', [
            'room_id'      => $room->id,
            'status_field' => 'cleanliness',
            'from_status'  => null,
            'to_status'    => 'dirty',
            'action'       => 'room_created',
        ]);
    }
}
