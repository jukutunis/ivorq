<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Models\Stay;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\TestCase;

class PmsDashboardTest extends TestCase
{
    use RefreshDatabase, CreatesPmsData;

    private function boot(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->seedPmsPermissions();

        config()->set('inertia.testing.ensure_pages_exist', false);

        return compact('company', 'property', 'admin');
    }

    public function test_dashboard_displays_arrivals_today(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);

        // Arrival today
        $this->makePmsReservation($property, $guest, [
            'arrival_date' => today()->toDateString(),
            'status' => \Modules\Operations\PMS\Enums\ReservationStatusEnum::Confirmed->value,
        ]);

        // Not arrival today
        $this->makePmsReservation($property, $guest, [
            'arrival_date' => today()->addDay()->toDateString(),
        ]);

        $this->get('/operations/pms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operations/PMS/Dashboard/Index')
                ->where('stats.arrivals_today', 1)
            );
    }

    public function test_dashboard_displays_departures_today(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);

        // Departure today
        $this->makePmsReservation($property, $guest, [
            'departure_date' => today()->toDateString(),
            'status' => \Modules\Operations\PMS\Enums\ReservationStatusEnum::CheckedIn->value,
        ]);

        // Not departure today
        $this->makePmsReservation($property, $guest, [
            'departure_date' => today()->addDay()->toDateString(),
        ]);

        $this->get('/operations/pms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.departures_today', 1)
            );
    }

    public function test_dashboard_displays_in_house_count(): void
    {
        ['property' => $property] = $this->boot();
        $guest = $this->makePmsGuest($property);
        $room = $this->makePmsRoom($property);
        $reservation = $this->makePmsReservation($property, $guest);

        $this->makePmsStay($reservation, $room, $guest, [
            'status' => \Modules\Operations\PMS\Enums\StayStatusEnum::CheckedIn->value,
        ]);

        $this->get('/operations/pms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.in_house_count', 1)
            );
    }

    public function test_dashboard_displays_available_rooms(): void
    {
        ['property' => $property] = $this->boot();

        // Available room
        $this->makePmsRoom($property, [
            'occupancy_status' => RoomOccupancyStatusEnum::Vacant->value,
            'is_active' => true,
        ]);

        // Occupied room
        $this->makePmsRoom($property, [
            'occupancy_status' => RoomOccupancyStatusEnum::Occupied->value,
            'is_active' => true,
        ]);

        $this->get('/operations/pms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.available_rooms', 1)
            );
    }

    public function test_dashboard_enforces_property_isolation(): void
    {
        ['company' => $company, 'property' => $propertyA] = $this->boot();
        $propertyB = $this->createProperty($company, ['code' => 'P-B']);

        $guestA = $this->makePmsGuest($propertyA);
        $guestB = $this->makePmsGuest($propertyB);

        // Property A Reservation
        $this->makePmsReservation($propertyA, $guestA, [
            'arrival_date' => today()->toDateString(),
            'status' => \Modules\Operations\PMS\Enums\ReservationStatusEnum::Confirmed->value,
        ]);

        // Property B Reservation
        $this->makePmsReservation($propertyB, $guestB, [
            'arrival_date' => today()->toDateString(),
            'status' => \Modules\Operations\PMS\Enums\ReservationStatusEnum::Confirmed->value,
        ]);

        // We are logged in as admin of propertyA and property context is A
        $this->get('/operations/pms')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.arrivals_today', 1)
            );
    }
}
