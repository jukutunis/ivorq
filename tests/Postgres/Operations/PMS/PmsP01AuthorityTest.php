<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\AvailabilityService;
use Modules\Operations\PMS\Services\FolioService;
use Modules\Operations\PMS\Services\FrontDeskService;
use Modules\Operations\PMS\Services\GuestService;
use Modules\Operations\PMS\Services\RatePlanService;
use Modules\Operations\PMS\Services\ReservationService;
use Modules\Operations\PMS\Services\RoomBlockService;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\PostgresTestCase;

class PmsP01AuthorityTest extends PostgresTestCase
{
    use CreatesPmsData, RefreshDatabase;

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $actor = $this->createPropertyAdmin($property);
        $this->actingAs($actor);
        app(CurrentPropertyService::class)->setId($property->id);
        $guest = $this->makePmsGuest($property);

        return [$property, $actor, $guest];
    }

    private function input(string $guestId): array
    {
        return [
            'primary_guest_id' => $guestId,
            'arrival_date' => '2026-12-01',
            'departure_date' => '2026-12-03',
            'adults' => 1,
            'children' => 0,
            'reserved_room_type' => RoomTypeEnum::Standard->value,
        ];
    }

    private function assertDenied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Expected denial.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }

    public function test_direct_service_requires_live_actor_membership_and_explicit_property(): void
    {
        [$property, $actor] = $this->fixture();
        $service = app(ReservationService::class);
        app(CurrentPropertyService::class)->clear();
        $this->assertDenied(fn () => $service->paginate(), 403);

        app(CurrentPropertyService::class)->setId($property->id);
        DB::table('property_user')->where('property_id', $property->id)
            ->where('user_id', $actor->id)->update(['status' => 'inactive']);
        $this->assertDenied(fn () => $service->paginate(), 403);

        DB::table('property_user')->where('property_id', $property->id)
            ->where('user_id', $actor->id)->update(['status' => 'active']);
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertDenied(fn () => $service->paginate(), 403);
    }

    public function test_server_owned_identity_dates_and_property_links(): void
    {
        [$property, , $guest] = $this->fixture();
        $service = app(ReservationService::class);
        $first = $service->create($this->input($guest->id));
        $second = $service->create($this->input($guest->id));
        $this->assertSame('RES-00001', $first->reservation_number);
        $this->assertSame('RES-00002', $second->reservation_number);
        $this->assertSame(2, $first->nights);
        $this->assertSame(1, DB::table('reservation_guests')->where('reservation_id', $first->id)
            ->where('guest_id', $guest->id)->where('is_primary', true)->count());

        try {
            $service->create($this->input($guest->id) + ['status' => 'confirmed']);
            $this->fail('Caller-owned status must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(2, Reservation::where('property_id', $property->id)->count());
        }

        $otherCompany = $this->createCompany();
        $otherProperty = $this->createProperty($otherCompany);
        $foreignGuest = $this->makePmsGuest($otherProperty);
        $this->expectException(ValidationException::class);
        $service->create($this->input($foreignGuest->id));
    }

    public function test_related_pms_services_reject_foreign_targets_and_unprivileged_actor(): void
    {
        [$property, , $guest] = $this->fixture();
        $otherCompany = $this->createCompany();
        $otherProperty = $this->createProperty($otherCompany);
        $foreignGuest = $this->makePmsGuest($otherProperty);
        $foreignRate = $this->makePmsRatePlan($otherProperty);
        $foreignRoom = $this->makePmsRoom($otherProperty);

        $this->assertDenied(fn () => app(GuestService::class)->find($foreignGuest->id), 404);
        $this->assertDenied(fn () => app(RatePlanService::class)->find($foreignRate->id), 404);
        $this->assertDenied(fn () => app(RoomBlockService::class)->create([
            'room_id' => $foreignRoom->id, 'block_type' => 'maintenance', 'start_at' => '2026-12-01',
        ]), 422);
        $this->assertDenied(fn () => app(AvailabilityService::class)->availableCount(
            RoomTypeEnum::Standard->value, '2026-12-01', '2026-12-03', null, $otherProperty->id,
        ), 403);
        $this->assertSame(0, DB::table('room_blocks')->where('property_id', $property->id)->count());
        $this->assertSame(0, DB::table('reservations')->where('property_id', $property->id)->count());

        $staff = $this->createUser($property, 'staff');
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);
        $this->assertDenied(fn () => app(ReservationService::class)->create($this->input($guest->id)), 403);
        $this->assertDenied(fn () => app(GuestService::class)->paginate(), 403);
    }

    public function test_partial_amendment_and_controlled_stay_protect_commercial_state(): void
    {
        [$property, $actor, $guest] = $this->fixture();
        $room = $this->makePmsRoom($property);
        $service = app(ReservationService::class);
        $reservation = $service->create($this->input($guest->id));

        try {
            $service->update($reservation->id, ['arrival_date' => '2026-12-04']);
            $this->fail('Merged dates must be validated.');
        } catch (ValidationException) {
            $this->assertSame(2, $reservation->fresh()->nights);
        }

        $this->assertSame(ReservationStatusEnum::Confirmed, $service->confirm($reservation->id)->status);
        FrontDeskStay::create([
            'property_id' => $property->id, 'reservation_id' => $reservation->id,
            'guest_id' => $guest->id, 'status' => FrontDeskStayStatusEnum::RoomAssigned,
            'current_room_id' => $room->id, 'created_by' => $actor->id,
        ]);
        $this->assertDenied(fn () => $service->cancel($reservation->id, 'Changed plans'), 409);
        $this->assertDenied(fn () => $service->noShow($reservation->id), 409);
        $this->assertDenied(fn () => $service->update($reservation->id, ['primary_guest_id' => $guest->id]), 409);
        $this->assertSame(ReservationStatusEnum::Confirmed, $reservation->fresh()->status);
        $this->assertSame(0, AuditLog::where('auditable_id', $reservation->id)
            ->where('event', 'pms_reservation_cancelled')->count());
    }

    public function test_supported_lifecycle_transitions_keep_minimized_durable_reason(): void
    {
        [$property, , $guest] = $this->fixture();
        $this->makePmsRoom($property);
        $service = app(ReservationService::class);
        $cancelled = $service->create($this->input($guest->id));
        $service->confirm($cancelled->id);
        $this->assertSame(ReservationStatusEnum::Cancelled,
            $service->cancel($cancelled->id, 'Guest requested cancellation')->status);
        $audit = AuditLog::query()->where('auditable_id', $cancelled->id)
            ->where('event', 'pms_reservation_cancelled')->firstOrFail();
        $this->assertSame(['reason' => 'Guest requested cancellation'], $audit->new_values);
        $activity = DB::table('activity_logs')->where('subject_type', Reservation::class)
            ->where('subject_id', $cancelled->id)->pluck('description')->implode(' ');
        $this->assertStringNotContainsString('Guest requested cancellation', $activity);
        $this->assertStringNotContainsString($guest->full_name, $activity);

        $noShow = $service->create($this->input($guest->id));
        $service->confirm($noShow->id);
        $this->assertSame(ReservationStatusEnum::NoShow, $service->noShow($noShow->id)->status);
        $this->assertSame(1, AuditLog::query()->where('auditable_id', $noShow->id)
            ->where('event', 'pms_reservation_no_show')->count());
    }

    public function test_guest_relationships_and_legacy_commands_fail_closed(): void
    {
        [$property, , $primary] = $this->fixture();
        $second = app(GuestService::class)->create(['full_name' => 'Accompanying Guest']);
        $reservation = app(ReservationService::class)->create($this->input($primary->id));
        $guests = app(GuestService::class);
        $guests->addAccompanyingGuest($reservation->id, $second->id);
        $this->assertSame(1, DB::table('reservation_guests')->where('reservation_id', $reservation->id)
            ->where('guest_id', $second->id)->count());
        $this->assertDenied(fn () => $guests->addAccompanyingGuest($reservation->id, $second->id), 409);
        $this->assertDenied(fn () => $guests->delete($second->id), 409);

        $this->assertDenied(fn () => app(ReservationService::class)->assignRoom($reservation->id, 'invalid'), 409);
        $this->assertDenied(fn () => app(FrontDeskService::class)->checkIn($reservation->id), 409);
        $this->assertDenied(fn () => app(FrontDeskService::class)->checkOut('invalid'), 409);
        $this->assertDenied(fn () => app(FolioService::class)->close('invalid'), 409);
        $this->assertDenied(fn () => app(FolioService::class)->void('invalid'), 409);
        $this->assertSame(0, DB::table('stays')->where('property_id', $property->id)->count());

        $guests->removeAccompanyingGuest($reservation->id, $second->id);
        $this->assertSame(0, DB::table('reservation_guests')->where('reservation_id', $reservation->id)
            ->where('guest_id', $second->id)->count());
        app(ReservationService::class)->update($reservation->id, ['primary_guest_id' => $second->id]);
        $this->assertSame($second->id, $reservation->fresh()->primary_guest_id);
        $this->assertSame(1, DB::table('reservation_guests')->where('reservation_id', $reservation->id)
            ->where('guest_id', $second->id)->where('is_primary', true)->count());
        $this->assertSame(0, DB::table('reservation_guests')->where('reservation_id', $reservation->id)
            ->where('guest_id', $primary->id)->count());
        $audit = AuditLog::where('auditable_id', $reservation->id)
            ->where('event', 'pms_reservation_amended')->firstOrFail();
        $this->assertSame(['fields' => ['primary_guest_id', 'nights']], $audit->new_values);
    }

    public function test_authenticated_http_routes_reject_foreign_targets_and_browser_authority(): void
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $actor = $this->createPropertyAdmin($property);
        $guest = $this->makePmsGuest($property);
        $reservation = $this->makePmsReservation($property, $guest);
        $room = $this->makePmsRoom($property);
        $otherCompany = $this->createCompany();
        $otherProperty = $this->createProperty($otherCompany);
        $foreignGuest = $this->makePmsGuest($otherProperty);
        $foreignReservation = $this->makePmsReservation($otherProperty, $foreignGuest);

        $login = $this->withSession(['login.tenant_id' => $property->company_id])->post('/login', [
            'email' => $actor->email, 'password' => 'password',
        ]);
        $login->assertRedirect('/frontdesk');
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());

        $this->get('/operations/pms/reservations/'.$reservation->id)->assertOk();
        $this->post('/operations/pms/reservations/'.$reservation->id.'/assign-room', [
            'room_id' => $room->id,
        ])->assertStatus(409);
        $this->post('/operations/pms/reservations/'.$reservation->id.'/check-in', [])
            ->assertStatus(409);
        $this->get('/operations/pms/reservations/'.$foreignReservation->id)->assertNotFound();
        $this->get('/operations/pms/guests/'.$foreignGuest->id)->assertNotFound();
        $this->post('/operations/pms/reservations', $this->input($guest->id) + [
            'property_id' => $otherProperty->id, 'status' => 'confirmed',
            'assigned_room_id' => $room->id,
        ])->assertSessionHasErrors(['property_id', 'status', 'assigned_room_id']);
        $this->assertSame(0, DB::table('stays')->where('reservation_id', $reservation->id)->count());
        $this->assertNull($reservation->fresh()->assigned_room_id);
    }
}
