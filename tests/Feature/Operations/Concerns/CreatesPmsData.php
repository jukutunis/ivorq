<?php

namespace Tests\Feature\Operations\Concerns;

use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Database\Seeders\PmsPermissionSeeder;
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
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Models\Stay;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait CreatesPmsData
{
    use CreatesOperationsData;

    /**
     * Seed PMS permissions and re-sync property-admin and super-admin roles
     * so they receive all PMS permissions.
     *
     * Must be called after createPropertyAdmin() / seedPermissionsAndRoles() has
     * already run, since those methods create the roles first.
     */
    protected function seedPmsPermissions(): void
    {
        $this->seed(PmsPermissionSeeder::class);

        foreach (['property-admin', 'super-admin'] as $roleName) {
            Role::where('name', $roleName)->first()
                ?->syncPermissions(Permission::all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makePmsRoom(Property $property, array $overrides = []): Room
    {
        static $seq = 0;
        $seq++;

        return Room::create(array_merge([
            'property_id'        => $property->id,
            'room_number'        => "20{$seq}",
            'room_type'          => RoomTypeEnum::Standard->value,
            'cleanliness_status' => RoomCleanlinessStatusEnum::Clean->value,
            'occupancy_status'   => RoomOccupancyStatusEnum::Vacant->value,
            'is_active'          => true,
        ], $overrides));
    }

    protected function makePmsGuest(Property $property, array $overrides = []): Guest
    {
        static $seq = 0;
        $seq++;

        return Guest::create(array_merge([
            'property_id' => $property->id,
            'guest_code'  => "POL-GST-{$seq}",
            'full_name'   => "Policy Guest {$seq}",
            'guest_type'  => GuestTypeEnum::Individual->value,
        ], $overrides));
    }

    protected function makePmsRatePlan(Property $property, array $overrides = []): RatePlan
    {
        static $seq = 0;
        $seq++;

        return RatePlan::create(array_merge([
            'property_id' => $property->id,
            'rate_code'   => "POL-RATE-{$seq}",
            'rate_name'   => "Policy Rate {$seq}",
            'plan_type'   => RatePlanTypeEnum::Nightly->value,
            'base_rate'   => 100.00,
            'currency'    => 'USD',
            'is_active'   => true,
        ], $overrides));
    }

    protected function makePmsReservation(Property $property, Guest $guest, array $overrides = []): Reservation
    {
        static $seq = 0;
        $seq++;

        return Reservation::create(array_merge([
            'property_id'        => $property->id,
            'reservation_number' => "POL-RES-{$seq}",
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 1,
            'children'           => 0,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ], $overrides));
    }

    protected function makePmsRoomBlock(Room $room, array $overrides = []): RoomBlock
    {
        return RoomBlock::create(array_merge([
            'property_id' => $room->property_id,
            'room_id'     => $room->id,
            'block_type'  => RoomBlockTypeEnum::OutOfOrder->value,
            'status'      => RoomBlockStatusEnum::Active->value,
            'reason'      => RoomBlockReasonEnum::Maintenance->value,
            'start_at'    => now()->subHour(),
            'end_at'      => now()->addDay(),
        ], $overrides));
    }

    protected function makePmsStay(Reservation $reservation, Room $room, Guest $guest, array $overrides = []): Stay
    {
        return Stay::create(array_merge([
            'property_id'           => $reservation->property_id,
            'reservation_id'        => $reservation->id,
            'room_id'               => $room->id,
            'guest_id'              => $guest->id,
            'status'                => StayStatusEnum::CheckedIn->value,
            'check_in_at'           => now(),
            'expected_departure_at' => now()->addDays(2),
        ], $overrides));
    }

    protected function makePmsFolio(Reservation $reservation, Guest $guest, array $overrides = []): Folio
    {
        static $seq = 0;
        $seq++;

        $folio = new Folio();
        $folio->forceFill(array_merge([
            'property_id'              => $reservation->property_id,
            'folio_number'             => "POL-FOL-{$seq}",
            'reservation_id'           => $reservation->id,
            'guest_id'                 => $guest->id,
            'status'                   => FolioStatusEnum::Open->value,
            'currency'                 => 'USD',
            'window_number'            => $seq,
            'opening_idempotency_key'  => 'test-legacy-pol-' . \Illuminate\Support\Str::ulid(),
            'total_charges'            => '0.00',
            'total_payments'           => '0.00',
            'balance'                  => '0.00',
        ], $overrides))->save();

        return $folio->fresh();
    }
}
