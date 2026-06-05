<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Models\Stay;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class PmsPolicyTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    // ─────────────────────────────────────────────────────────────────────────
    // Shared boot helper
    // ─────────────────────────────────────────────────────────────────────────

    private function bootAdmin(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->seedPmsPermissions();
        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GuestPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_guest_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', Guest::class)->allowed());
    }

    public function test_guest_policy_property_admin_can_view_own_guest(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest = $this->makePmsGuest($property);

        $this->assertTrue(Gate::inspect('view', $guest)->allowed());
    }

    public function test_guest_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'G-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest = $this->makePmsGuest($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $guest)->denied());
    }

    public function test_guest_policy_super_admin_can_view_any_property_guest(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest = $this->makePmsGuest($propertyA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('view', $guest)->allowed());
    }

    public function test_guest_policy_staff_cannot_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', Guest::class)->denied());
    }

    public function test_guest_policy_staff_cannot_delete(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($property->id);
        $guest = $this->makePmsGuest($property);

        $this->actingAs($staff);

        $this->assertTrue(Gate::inspect('delete', $guest)->denied());
    }

    public function test_guest_policy_property_admin_can_update(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest = $this->makePmsGuest($property);

        $this->assertTrue(Gate::inspect('update', $guest)->allowed());
    }

    public function test_guest_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'G-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest = $this->makePmsGuest($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $guest)->denied());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reservation_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', Reservation::class)->allowed());
    }

    public function test_reservation_policy_property_admin_can_view_own_reservation(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->assertTrue(Gate::inspect('view', $reservation)->allowed());
    }

    public function test_reservation_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $reservation)->denied());
    }

    public function test_reservation_policy_super_admin_can_view_cross_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('view', $reservation)->allowed());
    }

    public function test_reservation_policy_staff_cannot_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', Reservation::class)->denied());
    }

    public function test_reservation_policy_property_admin_can_check_in(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->assertTrue(Gate::inspect('checkIn', $reservation)->allowed());
    }

    public function test_reservation_policy_staff_cannot_check_in(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($property->id);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->actingAs($staff);

        $this->assertTrue(Gate::inspect('checkIn', $reservation)->denied());
    }

    public function test_reservation_policy_check_in_denies_cross_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('checkIn', $reservation)->denied());
    }

    public function test_reservation_policy_property_admin_can_check_out(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->assertTrue(Gate::inspect('checkOut', $reservation)->allowed());
    }

    public function test_reservation_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'R-PB03']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $reservation)->denied());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RoomBlockPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_room_block_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', RoomBlock::class)->allowed());
    }

    public function test_room_block_policy_property_admin_can_view_own_block(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $room  = $this->makePmsRoom($property);
        $block = $this->makePmsRoomBlock($room);

        $this->assertTrue(Gate::inspect('view', $block)->allowed());
    }

    public function test_room_block_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RB-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room  = $this->makePmsRoom($propertyA);
        $block = $this->makePmsRoomBlock($room);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $block)->denied());
    }

    public function test_room_block_policy_staff_cannot_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', RoomBlock::class)->denied());
    }

    public function test_room_block_policy_property_admin_can_change_status(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $room  = $this->makePmsRoom($property);
        $block = $this->makePmsRoomBlock($room);

        $this->assertTrue(Gate::inspect('changeStatus', $block)->allowed());
    }

    public function test_room_block_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RB-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $room  = $this->makePmsRoom($propertyA);
        $block = $this->makePmsRoomBlock($room);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $block)->denied());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // StayPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stay_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', Stay::class)->allowed());
    }

    public function test_stay_policy_property_admin_can_view_own_stay(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $this->assertTrue(Gate::inspect('view', $stay)->allowed());
    }

    public function test_stay_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'ST-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $room        = $this->makePmsRoom($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $stay)->denied());
    }

    public function test_stay_policy_property_admin_can_check_out(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $this->assertTrue(Gate::inspect('checkOut', $stay)->allowed());
    }

    public function test_stay_policy_check_out_denies_cross_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'ST-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $room        = $this->makePmsRoom($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('checkOut', $stay)->denied());
    }

    public function test_stay_policy_staff_cannot_check_out(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($property->id);
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $this->actingAs($staff);

        $this->assertTrue(Gate::inspect('checkOut', $stay)->denied());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_folio_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', Folio::class)->allowed());
    }

    public function test_folio_policy_property_admin_can_view_own_folio(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->assertTrue(Gate::inspect('view', $folio)->allowed());
    }

    public function test_folio_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'F-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $folio)->denied());
    }

    public function test_folio_policy_property_admin_can_manage(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->assertTrue(Gate::inspect('manage', $folio)->allowed());
    }

    public function test_folio_policy_staff_cannot_manage(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($property->id);
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->actingAs($staff);

        $this->assertTrue(Gate::inspect('manage', $folio)->denied());
    }

    public function test_folio_policy_manage_denies_cross_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'F-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('manage', $folio)->denied());
    }

    public function test_folio_policy_super_admin_can_manage_cross_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $guest       = $this->makePmsGuest($propertyA);
        $reservation = $this->makePmsReservation($propertyA, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('manage', $folio)->allowed());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RatePlanPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rate_plan_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', RatePlan::class)->allowed());
    }

    public function test_rate_plan_policy_property_admin_can_view_own_plan(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $plan = $this->makePmsRatePlan($property);

        $this->assertTrue(Gate::inspect('view', $plan)->allowed());
    }

    public function test_rate_plan_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RP-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $plan = $this->makePmsRatePlan($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $plan)->denied());
    }

    public function test_rate_plan_policy_property_admin_can_create(): void
    {
        $this->bootAdmin();

        $this->assertTrue(Gate::inspect('create', RatePlan::class)->allowed());
    }

    public function test_rate_plan_policy_staff_cannot_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedPmsPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', RatePlan::class)->denied());
    }

    public function test_rate_plan_policy_property_admin_can_update(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $plan = $this->makePmsRatePlan($property);

        $this->assertTrue(Gate::inspect('update', $plan)->allowed());
    }

    public function test_rate_plan_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'RP-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $plan = $this->makePmsRatePlan($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $plan)->denied());
    }

    public function test_rate_plan_policy_property_admin_can_delete(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $plan = $this->makePmsRatePlan($property);

        $this->assertTrue(Gate::inspect('delete', $plan)->allowed());
    }

    public function test_rate_plan_policy_super_admin_can_manage_cross_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedPmsPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $plan = $this->makePmsRatePlan($propertyA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('update', $plan)->allowed());
        $this->assertTrue(Gate::inspect('delete', $plan)->allowed());
    }
}
