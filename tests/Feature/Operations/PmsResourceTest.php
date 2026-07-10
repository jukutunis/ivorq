<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
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
use Modules\Operations\PMS\Http\Resources\FolioItemResource;
use Modules\Operations\PMS\Http\Resources\FolioResource;
use Modules\Operations\PMS\Http\Resources\GuestResource;
use Modules\Operations\PMS\Http\Resources\RatePlanResource;
use Modules\Operations\PMS\Http\Resources\ReservationResource;
use Modules\Operations\PMS\Http\Resources\RoomBlockResource;
use Modules\Operations\PMS\Http\Resources\StayResource;
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

class PmsResourceTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bootProperty(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('property', 'admin');
    }

    /** Serialize a resource and return the filtered array. */
    private function resolve(JsonResource $resource): array
    {
        return $resource->resolve(request());
    }

    private function hidden(): array
    {
        return ['created_by', 'updated_by', 'deleted_at'];
    }

    private function assertHiddenFieldsAbsent(array $data): void
    {
        foreach ($this->hidden() as $field) {
            $this->assertArrayNotHasKey($field, $data,
                "Field '{$field}' must not be exposed in PMS resources"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GuestResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_guest_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest = $this->makePmsGuest($property);

        $data = $this->resolve(new GuestResource($guest));

        $this->assertArrayHasKey('id',          $data);
        $this->assertArrayHasKey('property_id', $data);
        $this->assertArrayHasKey('guest_code',  $data);
        $this->assertArrayHasKey('full_name',   $data);
        $this->assertArrayHasKey('guest_type',  $data);
        $this->assertArrayHasKey('created_at',  $data);
        $this->assertArrayHasKey('updated_at',  $data);

        $this->assertSame($guest->id,        $data['id']);
        $this->assertSame($guest->full_name, $data['full_name']);
    }

    public function test_guest_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $data = $this->resolve(new GuestResource($this->makePmsGuest($property)));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_guest_resource_enum_has_value_and_label(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest = $this->makePmsGuest($property);

        $data = $this->resolve(new GuestResource($guest));

        $this->assertIsArray($data['guest_type']);
        $this->assertArrayHasKey('value', $data['guest_type']);
        $this->assertArrayHasKey('label', $data['guest_type']);
        $this->assertSame(GuestTypeEnum::Individual->value, $data['guest_type']['value']);
    }

    public function test_guest_resource_nested_relations_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest = Guest::find($this->makePmsGuest($property)->id); // no with()

        $data = $this->resolve(new GuestResource($guest));

        $this->assertArrayNotHasKey('reservations', $data);
        $this->assertArrayNotHasKey('stays',        $data);
        $this->assertArrayNotHasKey('folios',       $data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RatePlanResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rate_plan_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $plan = $this->makePmsRatePlan($property);

        $data = $this->resolve(new RatePlanResource($plan));

        $this->assertArrayHasKey('id',          $data);
        $this->assertArrayHasKey('rate_code',   $data);
        $this->assertArrayHasKey('rate_name',   $data);
        $this->assertArrayHasKey('plan_type',   $data);
        $this->assertArrayHasKey('base_rate',   $data);
        $this->assertArrayHasKey('currency',    $data);
        $this->assertArrayHasKey('is_active',   $data);
    }

    public function test_rate_plan_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $data = $this->resolve(new RatePlanResource($this->makePmsRatePlan($property)));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_rate_plan_resource_base_rate_is_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $plan = $this->makePmsRatePlan($property, ['base_rate' => 150.00]);

        $data = $this->resolve(new RatePlanResource($plan));

        $this->assertIsFloat($data['base_rate']);
        $this->assertSame(150.0, $data['base_rate']);
    }

    public function test_rate_plan_resource_plan_type_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();
        $data = $this->resolve(new RatePlanResource($this->makePmsRatePlan($property)));

        $this->assertIsArray($data['plan_type']);
        $this->assertSame(RatePlanTypeEnum::Nightly->value, $data['plan_type']['value']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReservationResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reservation_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertArrayHasKey('id',                  $data);
        $this->assertArrayHasKey('reservation_number',  $data);
        $this->assertArrayHasKey('primary_guest_id',    $data);
        $this->assertArrayHasKey('arrival_date',        $data);
        $this->assertArrayHasKey('departure_date',      $data);
        $this->assertArrayHasKey('nights',              $data);
        $this->assertArrayHasKey('status',              $data);
        $this->assertArrayHasKey('reserved_room_type',  $data);
        $this->assertArrayHasKey('reservation_source',  $data);
    }

    public function test_reservation_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_reservation_resource_status_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertIsArray($data['status']);
        $this->assertArrayHasKey('value', $data['status']);
        $this->assertArrayHasKey('label', $data['status']);
        $this->assertSame(ReservationStatusEnum::Tentative->value, $data['status']['value']);
    }

    public function test_reservation_resource_arrival_date_is_iso_date_string(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest, [
            'arrival_date'   => '2026-07-01',
            'departure_date' => '2026-07-03',
        ]);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertSame('2026-07-01', $data['arrival_date']);
        $this->assertSame('2026-07-03', $data['departure_date']);
    }

    public function test_reservation_resource_nested_relations_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = Reservation::find($this->makePmsReservation($property, $guest)->id);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertArrayNotHasKey('primary_guest',  $data);
        $this->assertArrayNotHasKey('guests',         $data);
        $this->assertArrayNotHasKey('rate_plan',      $data);
        $this->assertArrayNotHasKey('assigned_room',  $data);
        $this->assertArrayNotHasKey('stays',          $data);
        $this->assertArrayNotHasKey('folios',         $data);
    }

    public function test_reservation_resource_primary_guest_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = Reservation::with('primaryGuest')
            ->find($this->makePmsReservation($property, $guest)->id);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertArrayHasKey('primary_guest', $data);
        $this->assertSame($guest->id, $data['primary_guest']['id']);
    }

    public function test_reservation_resource_stays_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $this->makePmsStay($reservation, $room, $guest);

        $reservation = Reservation::with('stays')
            ->find($reservation->id);

        $data = $this->resolve(new ReservationResource($reservation));

        $this->assertArrayHasKey('stays', $data);
        $this->assertCount(1, $data['stays']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RoomBlockResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_room_block_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $room  = $this->makePmsRoom($property);
        $block = $this->makePmsRoomBlock($room);

        $data = $this->resolve(new RoomBlockResource($block));

        $this->assertArrayHasKey('id',          $data);
        $this->assertArrayHasKey('property_id', $data);
        $this->assertArrayHasKey('room_id',     $data);
        $this->assertArrayHasKey('block_type',  $data);
        $this->assertArrayHasKey('status',      $data);
        $this->assertArrayHasKey('start_at',    $data);
        $this->assertArrayHasKey('released_at', $data);
        $this->assertArrayHasKey('released_by', $data);
    }

    public function test_room_block_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $room  = $this->makePmsRoom($property);
        $data  = $this->resolve(new RoomBlockResource($this->makePmsRoomBlock($room)));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_room_block_resource_reason_is_nullable_enum(): void
    {
        ['property' => $property] = $this->bootProperty();
        $room  = $this->makePmsRoom($property);

        // With reason
        $blockWith = $this->makePmsRoomBlock($room, ['reason' => RoomBlockReasonEnum::Maintenance->value]);
        $data      = $this->resolve(new RoomBlockResource($blockWith));
        $this->assertIsArray($data['reason']);
        $this->assertSame(RoomBlockReasonEnum::Maintenance->value, $data['reason']['value']);

        // Without reason
        $blockWithout = $this->makePmsRoomBlock($room, [
            'reason'   => null,
            'start_at' => now()->addDays(5),
            'end_at'   => now()->addDays(7),
        ]);
        $data = $this->resolve(new RoomBlockResource($blockWithout));
        $this->assertNull($data['reason']);
    }

    public function test_room_block_resource_room_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $room  = $this->makePmsRoom($property);
        $block = RoomBlock::find($this->makePmsRoomBlock($room)->id);

        $data = $this->resolve(new RoomBlockResource($block));

        $this->assertArrayNotHasKey('room', $data);
    }

    public function test_room_block_resource_room_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $room  = $this->makePmsRoom($property);
        $block = RoomBlock::with('room')->find($this->makePmsRoomBlock($room)->id);

        $data = $this->resolve(new RoomBlockResource($block));

        $this->assertArrayHasKey('room', $data);
        $this->assertSame($room->id,          $data['room']['id']);
        $this->assertSame($room->room_number, $data['room']['room_number']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // StayResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stay_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $data = $this->resolve(new StayResource($stay));

        $this->assertArrayHasKey('id',                    $data);
        $this->assertArrayHasKey('reservation_id',        $data);
        $this->assertArrayHasKey('room_id',               $data);
        $this->assertArrayHasKey('guest_id',              $data);
        $this->assertArrayHasKey('status',                $data);
        $this->assertArrayHasKey('check_in_at',           $data);
        $this->assertArrayHasKey('expected_departure_at', $data);
        $this->assertArrayHasKey('check_out_at',          $data);
        $this->assertArrayHasKey('duration_minutes',      $data);
    }

    public function test_stay_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $data = $this->resolve(new StayResource($this->makePmsStay($reservation, $room, $guest)));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_stay_resource_duration_minutes_null_when_not_checked_out(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest);

        $data = $this->resolve(new StayResource($stay));

        $this->assertNull($data['duration_minutes']);
    }

    public function test_stay_resource_duration_minutes_set_after_checkout(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = $this->makePmsStay($reservation, $room, $guest, [
            'check_in_at'  => now()->subHours(2),
            'check_out_at' => now(),
            'status'       => StayStatusEnum::CheckedOut->value,
        ]);

        $data = $this->resolve(new StayResource($stay));

        $this->assertNotNull($data['duration_minutes']);
        $this->assertGreaterThan(0, $data['duration_minutes']);
    }

    public function test_stay_resource_nested_relations_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = Stay::find($this->makePmsStay($reservation, $room, $guest)->id);

        $data = $this->resolve(new StayResource($stay));

        $this->assertArrayNotHasKey('reservation', $data);
        $this->assertArrayNotHasKey('guest',       $data);
        $this->assertArrayNotHasKey('room',        $data);
    }

    public function test_stay_resource_guest_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $room        = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $stay        = Stay::with('guest')->find($this->makePmsStay($reservation, $room, $guest)->id);

        $data = $this->resolve(new StayResource($stay));

        $this->assertArrayHasKey('guest', $data);
        $this->assertSame($guest->id,        $data['guest']['id']);
        $this->assertSame($guest->full_name, $data['guest']['full_name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_folio_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $data = $this->resolve(new FolioResource($folio));

        $this->assertArrayHasKey('id',             $data);
        $this->assertArrayHasKey('folio_number',   $data);
        $this->assertArrayHasKey('reservation_id', $data);
        $this->assertArrayHasKey('guest_id',       $data);
        $this->assertArrayHasKey('status',         $data);
        $this->assertArrayHasKey('currency',       $data);
        $this->assertArrayHasKey('total_charges',  $data);
        $this->assertArrayHasKey('total_payments', $data);
        $this->assertArrayHasKey('balance',        $data);
    }

    public function test_folio_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $data = $this->resolve(new FolioResource($this->makePmsFolio($reservation, $guest)));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_folio_resource_decimal_fields_are_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest, [
            'total_charges'  => 300.00,
            'total_payments' => 100.00,
            'balance'        => 200.00,
        ]);

        $data = $this->resolve(new FolioResource($folio));

        $this->assertIsFloat($data['total_charges']);
        $this->assertIsFloat($data['total_payments']);
        $this->assertIsFloat($data['balance']);
        $this->assertSame(300.0, $data['total_charges']);
        $this->assertSame(100.0, $data['total_payments']);
        $this->assertSame(200.0, $data['balance']);
    }

    public function test_folio_resource_nested_items_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = Folio::find($this->makePmsFolio($reservation, $guest)->id);

        $data = $this->resolve(new FolioResource($folio));

        $this->assertArrayNotHasKey('items',        $data);
        $this->assertArrayNotHasKey('active_items', $data);
        $this->assertArrayNotHasKey('guest',        $data);
        $this->assertArrayNotHasKey('reservation',  $data);
    }

    public function test_folio_resource_items_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        tap(new FolioItem())->forceFill([
            'property_id' => $property->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 150.00,
            'is_void'     => false,
            'posted_at'   => now(),
        ])->save();

        $folio = Folio::with('items')->find($folio->id);
        $data  = $this->resolve(new FolioResource($folio));

        $this->assertArrayHasKey('items', $data);
        $this->assertCount(1, $data['items']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FolioItemResource
    // ─────────────────────────────────────────────────────────────────────────

    public function test_folio_item_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $item = tap(new FolioItem())->forceFill([
            'property_id' => $property->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 150.00,
            'is_void'     => false,
            'posted_at'   => now(),
        ]);
        $item->save();

        $data = $this->resolve(new FolioItemResource($item));

        $this->assertArrayHasKey('id',          $data);
        $this->assertArrayHasKey('folio_id',    $data);
        $this->assertArrayHasKey('item_type',   $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('quantity',    $data);
        $this->assertArrayHasKey('amount',      $data);
        $this->assertArrayHasKey('is_void',     $data);
        $this->assertArrayHasKey('posted_at',   $data);
    }

    public function test_folio_item_resource_hides_audit_columns(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $item = tap(new FolioItem())->forceFill([
            'property_id' => $property->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Test',
            'quantity'    => 1,
            'amount'      => 50.00,
            'is_void'     => false,
            'posted_at'   => now(),
        ]);
        $item->save();

        $data = $this->resolve(new FolioItemResource($item));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_folio_item_resource_decimal_fields_are_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $item = tap(new FolioItem())->forceFill([
            'property_id' => $property->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge->value,
            'description' => 'Test',
            'quantity'    => 2,
            'amount'      => 75.50,
            'is_void'     => false,
            'posted_at'   => now(),
        ]);
        $item->save();

        $data = $this->resolve(new FolioItemResource($item));

        $this->assertIsFloat($data['quantity']);
        $this->assertIsFloat($data['amount']);
        $this->assertSame(2.0,   $data['quantity']);
        $this->assertSame(75.50, $data['amount']);
    }

    public function test_folio_item_resource_item_type_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();
        $guest       = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $folio       = $this->makePmsFolio($reservation, $guest);

        $item = tap(new FolioItem())->forceFill([
            'property_id' => $property->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::Payment->value,
            'description' => 'Cash payment',
            'quantity'    => 1,
            'amount'      => -100.00,
            'is_void'     => false,
            'posted_at'   => now(),
        ]);
        $item->save();

        $data = $this->resolve(new FolioItemResource($item));

        $this->assertIsArray($data['item_type']);
        $this->assertSame(FolioItemTypeEnum::Payment->value, $data['item_type']['value']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resource class existence and inheritance
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_pms_resource_classes_extend_json_resource(): void
    {
        $classes = [
            GuestResource::class,
            RatePlanResource::class,
            ReservationResource::class,
            RoomBlockResource::class,
            StayResource::class,
            FolioResource::class,
            FolioItemResource::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} must exist");
            $this->assertTrue(
                is_subclass_of($class, JsonResource::class),
                "{$class} must extend JsonResource"
            );
        }
    }
}
