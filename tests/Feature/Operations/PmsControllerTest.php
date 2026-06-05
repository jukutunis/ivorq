<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Models\Stay;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class PmsControllerTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    // ─────────────────────────────────────────────────────────────────────────
    // Shared boot helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bootAdmin(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dashboard
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_pms_dashboard(): void
    {
        $this->bootAdmin();

        $this->get('/operations/pms')
            ->assertOk();
    }

    public function test_staff_cannot_view_pms_dashboard(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->get('/operations/pms')
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GuestController
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_guests_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/pms/guests')
            ->assertOk();
    }

    public function test_staff_cannot_view_guests_index(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->get('/operations/pms/guests')
            ->assertForbidden();
    }

    public function test_admin_can_create_guest(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $this->post('/operations/pms/guests', [
            'full_name'  => 'John Doe',
            'email'      => 'john@example.com',
            'guest_type' => GuestTypeEnum::Individual->value,
        ])->assertRedirect();

        $this->assertDatabaseHas('guests', [
            'property_id' => $property->id,
            'full_name'   => 'John Doe',
        ]);
    }

    public function test_staff_cannot_create_guest(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post('/operations/pms/guests', [
            'full_name'  => 'Unauthorized',
            'guest_type' => GuestTypeEnum::Individual->value,
        ])->assertForbidden();

        $this->assertDatabaseMissing('guests', ['full_name' => 'Unauthorized']);
    }

    public function test_admin_can_view_own_guest(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest = $this->makePmsGuest($property);

        $this->get("/operations/pms/guests/{$guest->id}")
            ->assertOk();
    }

    public function test_cross_property_guest_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'G-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest = $this->makePmsGuest($propertyA);

        // BelongsToProperty global scope hides property A's guest from property B's context
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->get("/operations/pms/guests/{$guest->id}")
            ->assertNotFound();
    }

    public function test_admin_can_update_own_guest(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest = $this->makePmsGuest($property);

        $this->put("/operations/pms/guests/{$guest->id}", [
            'full_name' => 'Updated Name',
        ])->assertRedirect();

        $this->assertDatabaseHas('guests', [
            'id'        => $guest->id,
            'full_name' => 'Updated Name',
        ]);
    }

    public function test_admin_can_delete_own_guest(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest = $this->makePmsGuest($property);

        $this->delete("/operations/pms/guests/{$guest->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('guests', ['id' => $guest->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationController — CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_reservations_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/pms/reservations')
            ->assertOk();
    }

    public function test_admin_can_store_reservation(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest = $this->makePmsGuest($property);

        $this->post('/operations/pms/reservations', [
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 1,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ])->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'property_id'      => $property->id,
            'primary_guest_id' => $guest->id,
        ]);
    }

    public function test_staff_cannot_create_reservation(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $guest    = $this->makePmsGuest($property);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post('/operations/pms/reservations', [
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 1,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ])->assertForbidden();
    }

    public function test_cross_property_reservation_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        // BelongsToProperty global scope hides property A's reservation from property B's context
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->get("/operations/pms/reservations/{$reservation->id}")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationController — actions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_confirm_reservation(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        // Provide a room so availability check passes
        $this->makePmsRoom($property);

        $this->post("/operations/pms/reservations/{$reservation->id}/confirm")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Reservation confirmed.']);

        $this->assertDatabaseHas('reservations', [
            'id'     => $reservation->id,
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);
    }

    public function test_staff_cannot_confirm_reservation(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $guest    = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post("/operations/pms/reservations/{$reservation->id}/confirm")
            ->assertForbidden();
    }

    public function test_admin_can_cancel_reservation(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->post("/operations/pms/reservations/{$reservation->id}/cancel", [
            'reason' => 'Guest request',
        ])->assertOk()
          ->assertJsonFragment(['message' => 'Reservation cancelled.']);

        $this->assertDatabaseHas('reservations', [
            'id'     => $reservation->id,
            'status' => ReservationStatusEnum::Cancelled->value,
        ]);
    }

    public function test_admin_can_mark_no_show(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->post("/operations/pms/reservations/{$reservation->id}/no-show")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Reservation marked as no-show.']);
    }

    public function test_admin_can_assign_room(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->post("/operations/pms/reservations/{$reservation->id}/assign-room", [
            'room_id' => $room->id,
        ])->assertOk()
          ->assertJsonFragment(['message' => 'Room assigned successfully.']);

        $this->assertDatabaseHas('reservations', [
            'id'               => $reservation->id,
            'assigned_room_id' => $room->id,
        ]);
    }

    public function test_cross_property_reservation_confirm_denied(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-B02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->post("/operations/pms/reservations/{$reservation->id}/confirm")
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FrontDeskController
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_check_in(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest = $this->makePmsGuest($property);
        $room  = $this->makePmsRoom($property, [
            'cleanliness_status' => RoomCleanlinessStatusEnum::Clean->value,
            'occupancy_status'   => RoomOccupancyStatusEnum::Vacant->value,
        ]);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status'           => ReservationStatusEnum::Confirmed->value,
            'assigned_room_id' => $room->id,
        ]);

        $this->post("/operations/pms/reservations/{$reservation->id}/check-in")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Guest checked in successfully.']);

        $this->assertDatabaseHas('stays', [
            'reservation_id' => $reservation->id,
            'status'         => StayStatusEnum::CheckedIn->value,
        ]);
    }

    public function test_staff_cannot_check_in(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $guest    = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post("/operations/pms/reservations/{$reservation->id}/check-in")
            ->assertForbidden();
    }

    public function test_admin_can_check_out(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'status' => ReservationStatusEnum::CheckedIn->value,
        ]);
        $stay = $this->makePmsStay($reservation, $room, $guest, [
            'status' => StayStatusEnum::CheckedIn->value,
        ]);

        $this->post("/operations/pms/stays/{$stay->id}/check-out")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Guest checked out successfully.']);

        $this->assertDatabaseHas('stays', [
            'id'     => $stay->id,
            'status' => StayStatusEnum::CheckedOut->value,
        ]);
    }

    public function test_cross_property_check_in_denied(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'FD-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest, [
            'status' => ReservationStatusEnum::Confirmed->value,
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->post("/operations/pms/reservations/{$reservation->id}/check-in")
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RoomBlockController
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_room_blocks_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/pms/room-blocks')
            ->assertOk();
    }

    public function test_admin_can_create_room_block(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $room = $this->makePmsRoom($property);

        $this->post('/operations/pms/room-blocks', [
            'room_id'    => $room->id,
            'block_type' => RoomBlockTypeEnum::OutOfOrder->value,
            'reason'     => RoomBlockReasonEnum::Maintenance->value,
            'start_at'   => now()->addHour()->toDateTimeString(),
            'end_at'     => now()->addDays(2)->toDateTimeString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('room_blocks', [
            'room_id' => $room->id,
        ]);
    }

    public function test_staff_cannot_create_room_block(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $room     = $this->makePmsRoom($property);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post('/operations/pms/room-blocks', [
            'room_id'    => $room->id,
            'block_type' => RoomBlockTypeEnum::OutOfOrder->value,
            'start_at'   => now()->addHour()->toDateTimeString(),
            'end_at'     => now()->addDays(2)->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_admin_can_release_room_block(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $room  = $this->makePmsRoom($property);
        $block = $this->makePmsRoomBlock($room);

        $this->post("/operations/pms/room-blocks/{$block->id}/release")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Room block released.']);

        $this->assertDatabaseHas('room_blocks', [
            'id'     => $block->id,
            'status' => RoomBlockStatusEnum::Released->value,
        ]);
    }

    public function test_cross_property_room_block_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RB-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room  = $this->makePmsRoom($propertyA);
        $block = $this->makePmsRoomBlock($room);

        // BelongsToProperty global scope hides property A's block from property B's context
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->get("/operations/pms/room-blocks/{$block->id}")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioController
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_folios_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/pms/folios')
            ->assertOk();
    }

    public function test_admin_can_view_own_folio(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->get("/operations/pms/folios/{$folio->id}")
            ->assertOk();
    }

    public function test_cross_property_folio_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'F-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        // BelongsToProperty global scope hides property A's folio from property B's context
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->get("/operations/pms/folios/{$folio->id}")
            ->assertNotFound();
    }

    public function test_admin_can_post_folio_item(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->post("/operations/pms/folios/{$folio->id}/items", [
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge — night 1',
            'quantity'    => 1,
            'amount'      => 150.00,
        ])->assertOk()
          ->assertJsonFragment(['message' => 'Item posted to folio.']);

        $this->assertDatabaseHas('folio_items', [
            'folio_id'    => $folio->id,
            'description' => 'Room charge — night 1',
        ]);
    }

    public function test_staff_cannot_post_folio_item(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $guest    = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post("/operations/pms/folios/{$folio->id}/items", [
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Unauthorized charge',
            'quantity'    => 1,
            'amount'      => 100.00,
        ])->assertForbidden();
    }

    public function test_admin_can_void_folio_item(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $item = FolioItem::create([
            'property_id' => $property->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 100.00,
            'is_void'     => false,
            'posted_at'   => now(),
        ]);

        $this->post("/operations/pms/folio-items/{$item->id}/void")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Folio item voided.']);

        $this->assertDatabaseHas('folio_items', [
            'id'      => $item->id,
            'is_void' => true,
        ]);
    }

    public function test_admin_can_close_folio(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->post("/operations/pms/folios/{$folio->id}/close")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Folio closed.']);

        $this->assertDatabaseHas('folios', [
            'id'     => $folio->id,
            'status' => FolioStatusEnum::Closed->value,
        ]);
    }

    public function test_admin_can_void_folio(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->post("/operations/pms/folios/{$folio->id}/void")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Folio voided.']);

        $this->assertDatabaseHas('folios', [
            'id'     => $folio->id,
            'status' => FolioStatusEnum::Void->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RatePlanController
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_view_rate_plans_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/pms/rate-plans')
            ->assertOk();
    }

    public function test_admin_can_create_rate_plan(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $this->post('/operations/pms/rate-plans', [
            'rate_code' => 'BAR',
            'rate_name' => 'Best Available Rate',
            'plan_type' => RatePlanTypeEnum::Nightly->value,
            'base_rate' => 150.00,
            'currency'  => 'USD',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('rate_plans', [
            'property_id' => $property->id,
            'rate_code'   => 'BAR',
        ]);
    }

    public function test_staff_cannot_create_rate_plan(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->post('/operations/pms/rate-plans', [
            'rate_code' => 'UNAUTH',
            'rate_name' => 'Unauthorized Plan',
            'plan_type' => RatePlanTypeEnum::Nightly->value,
            'base_rate' => 100.00,
        ])->assertForbidden();

        $this->assertDatabaseMissing('rate_plans', ['rate_code' => 'UNAUTH']);
    }

    public function test_cross_property_rate_plan_update_denied(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RP-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $plan = $this->makePmsRatePlan($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->put("/operations/pms/rate-plans/{$plan->id}", [
            'rate_name' => 'Stolen Plan',
        ])->assertForbidden();
    }
}
