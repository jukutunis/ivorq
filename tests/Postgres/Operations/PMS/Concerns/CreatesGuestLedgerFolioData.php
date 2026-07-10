<?php

namespace Tests\Postgres\Operations\PMS\Concerns;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;

trait CreatesGuestLedgerFolioData
{
    protected Company $glfCompany;
    protected Property $glfProperty;
    protected Property $glfOtherProperty;
    protected User $glfActor;

    protected function setUpGuestLedgerFolioFixture(): void
    {
        $this->glfCompany = Company::create([
            'name'      => 'GLF-A Test Company',
            'slug'      => 'glf-a-test-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->glfProperty = Property::create([
            'company_id' => $this->glfCompany->id,
            'name'       => 'GLF-A Test Property',
            'slug'       => 'glf-a-test-prop-' . Str::lower(Str::random(6)),
            'code'       => 'GLFP' . Str::upper(Str::random(2)),
            'timezone'   => 'UTC',
            'currency'   => 'USD',
            'is_active'  => true,
        ]);

        $this->glfOtherProperty = Property::create([
            'company_id' => $this->glfCompany->id,
            'name'       => 'GLF-A Other Property',
            'slug'       => 'glf-a-other-prop-' . Str::lower(Str::random(6)),
            'code'       => 'GLFO' . Str::upper(Str::random(2)),
            'timezone'   => 'UTC',
            'currency'   => 'EUR',
            'is_active'  => true,
        ]);

        $this->glfActor = User::create([
            'name'      => 'GLF-A Actor',
            'email'     => 'glf-a-actor-' . Str::lower(Str::random(6)) . '@example.test',
            'password'  => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->glfActor->properties()->attach($this->glfProperty->id, [
            'is_default' => true,
            'status'     => 'active',
            'joined_at'  => now(),
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->glfProperty->id);
    }

    protected function makeGlfGuest(?Property $property = null): Guest
    {
        static $seq = 0;
        $seq++;

        return Guest::create([
            'property_id' => ($property ?? $this->glfProperty)->id,
            'guest_code'  => "GLF-GST-{$seq}",
            'full_name'   => "GLF Guest {$seq}",
            'guest_type'  => GuestTypeEnum::Individual->value,
        ]);
    }

    protected function makeGlfReservation(?Property $property = null, ?Guest $guest = null): Reservation
    {
        static $seq = 0;
        $seq++;

        $property = $property ?? $this->glfProperty;
        $guest    = $guest ?? $this->makeGlfGuest($property);

        return Reservation::create([
            'property_id'        => $property->id,
            'reservation_number' => "GLF-RES-{$seq}",
            'primary_guest_id'   => $guest->id,
            'arrival_date'       => today()->addDay()->toDateString(),
            'departure_date'     => today()->addDays(3)->toDateString(),
            'nights'             => 2,
            'adults'             => 1,
            'children'           => 0,
            'reservation_source' => ReservationSourceEnum::WalkIn->value,
            'status'             => ReservationStatusEnum::Tentative->value,
            'reserved_room_type' => 'standard',
        ]);
    }
}
