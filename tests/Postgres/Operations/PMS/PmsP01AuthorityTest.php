<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;
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

    public function test_retained_payment_without_folio_freezes_reservation_and_guest_relationships(): void
    {
        [$property, $actor, $guest] = $this->fixture();
        $service = app(ReservationService::class);
        $reservation = $service->create($this->input($guest->id));
        $other = app(GuestService::class)->create(['full_name' => 'Other Guest']);
        $sessionId = (string) Str::ulid();
        DB::table('cashier_sessions')->insert([
            'id' => $sessionId, 'property_id' => $property->id,
            'cashier_user_id' => $actor->id, 'status' => 'OPEN',
            'opened_at' => now(), 'opened_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('guest_payment_transactions')->insert([
            'id' => (string) Str::ulid(), 'property_id' => $property->id,
            'payment_number' => 'R1-PAY-1', 'reservation_id' => $reservation->id,
            'guest_id' => $guest->id, 'currency' => $property->currency,
            'amount' => '10.00', 'tender_type' => 'CASH', 'cashier_session_id' => $sessionId,
            'lifecycle_status' => 'VOIDED', 'recording_idempotency_key' => 'r1-payment',
            'recorded_at' => now(), 'recorded_by' => $actor->id, 'source_snapshot' => '{}',
            'created_at' => now(), 'updated_at' => now(), 'created_by' => $actor->id,
        ]);

        $this->assertSame(0, DB::table('folios')->where('reservation_id', $reservation->id)->count());
        $this->assertTrue($service->capabilities($reservation)['has_operational_evidence']);
        $this->assertFalse($service->capabilities($reservation)['can_edit']);
        $this->assertDenied(fn () => $service->update($reservation->id, ['primary_guest_id' => $other->id]), 409);
        $this->assertDenied(fn () => $service->confirm($reservation->id), 409);
        $this->assertDenied(fn () => $service->cancel($reservation->id), 409);
        $this->assertDenied(fn () => $service->delete($reservation->id), 409);
        $this->assertDenied(fn () => app(GuestService::class)->addAccompanyingGuest($reservation->id, $other->id), 409);
        $this->assertSame($guest->id, $reservation->fresh()->primary_guest_id);
        $this->assertSame(0, AuditLog::where('auditable_id', $reservation->id)
            ->whereIn('event', ['pms_reservation_amended', 'pms_reservation_confirmed',
                'pms_reservation_cancelled', 'pms_reservation_deleted', 'pms_reservation_guest_linked'])->count());

        $this->makePmsRoom($property);
        $this->makePmsRoom($property);
        $confirmed = $service->create($this->input($guest->id));
        $service->confirm($confirmed->id);
        app(GuestService::class)->addAccompanyingGuest($confirmed->id, $other->id);
        DB::table('guest_deposit_transactions')->insert([
            'id' => (string) Str::ulid(), 'property_id' => $property->id,
            'deposit_number' => 'R1-DEP-1', 'reservation_id' => $confirmed->id,
            'guest_id' => $guest->id, 'currency' => $property->currency,
            'amount' => '10.00', 'tender_type' => 'CASH', 'cashier_session_id' => $sessionId,
            'lifecycle_status' => 'VOIDED', 'recording_idempotency_key' => 'r1-deposit',
            'recorded_at' => now(), 'recorded_by' => $actor->id, 'source_snapshot' => '{}',
            'created_at' => now(), 'updated_at' => now(), 'created_by' => $actor->id,
        ]);
        $this->assertSame(0, DB::table('folios')->where('reservation_id', $confirmed->id)->count());
        $this->assertDenied(fn () => $service->noShow($confirmed->id), 409);
        $this->assertDenied(fn () => app(GuestService::class)
            ->removeAccompanyingGuest($confirmed->id, $other->id), 409);
        $this->assertSame(1, DB::table('reservation_guests')->where('reservation_id', $confirmed->id)
            ->where('guest_id', $other->id)->count());
        $this->assertSame(0, AuditLog::where('auditable_id', $confirmed->id)
            ->whereIn('event', ['pms_reservation_no_show', 'pms_reservation_guest_unlinked'])->count());
    }

    public function test_fresh_team_permissions_ignore_stale_authenticated_roles(): void
    {
        [$property, $actor, $guest] = $this->fixture();
        $service = app(ReservationService::class);
        $actor->load('roles', 'permissions');
        $this->assertSame(0, $service->paginate()->total());
        $previousTeam = 'other-team';
        setPermissionsTeamId($previousTeam);
        DB::table('model_has_roles')->where('model_id', $actor->id)
            ->where('model_type', $actor->getMorphClass())->delete();
        $this->assertDenied(fn () => $service->create($this->input($guest->id)), 403);
        $this->assertSame($previousTeam, getPermissionsTeamId());
        $this->assertSame(0, Reservation::where('property_id', $property->id)->count());
    }

    public function test_fresh_team_permissions_reject_revoked_role_permission_and_foreign_team(): void
    {
        [$property, $actor, $guest] = $this->fixture();
        $service = app(ReservationService::class);
        $actor->load('roles', 'permissions');
        $otherProperty = $this->createProperty($this->createCompany());
        $actor->properties()->attach($otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        app(CurrentPropertyService::class)->setId($otherProperty->id);
        try {
            $service->create($this->input($guest->id));
            $this->fail('Foreign-team authority was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        app(CurrentPropertyService::class)->setId($property->id);

        $roleId = DB::table('roles')->where('name', 'property-admin')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'pms.reservation.create')->value('id');
        DB::table('role_has_permissions')->where('role_id', $roleId)
            ->where('permission_id', $permissionId)->delete();
        try {
            $service->create($this->input($guest->id));
            $this->fail('Revoked role permission was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, Reservation::where('property_id', $property->id)->count());
    }

    public function test_direct_service_business_values_fail_before_mutation(): void
    {
        [$property, , $guest] = $this->fixture();
        $reservation = app(ReservationService::class);
        foreach ([
            ['arrival_date' => 'tomorrow'],
            ['arrival_date' => '2026-02-30'],
            ['adults' => 21],
            ['reserved_room_type' => 'unknown'],
            ['primary_guest_id' => null],
        ] as $bad) {
            try {
                $reservation->create(array_replace($this->input($guest->id), $bad));
                $this->fail('Invalid direct reservation input was accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Reservation::where('property_id', $property->id)->count());
            }
        }
        foreach ([
            fn () => app(GuestService::class)->create(['full_name' => null]),
            fn () => app(RatePlanService::class)->create(['rate_code' => 'X', 'rate_name' => 'X', 'plan_type' => 'invalid', 'base_rate' => 1]),
            fn () => app(RoomBlockService::class)->create(['room_id' => $this->makePmsRoom($property)->id,
                'block_type' => 'out_of_order', 'start_at' => 'next week']),
        ] as $invalid) {
            $this->expectInvalidDirectInput($invalid);
        }
        $valid = $reservation->create($this->input($guest->id));
        foreach ([['arrival_date' => 'tomorrow'], ['children' => 21],
            ['primary_guest_id' => null], ['departure_date' => '2026-12-01']] as $bad) {
            $this->expectInvalidDirectInput(fn () => $reservation->update($valid->id, $bad));
            $this->assertSame(2, $valid->fresh()->nights);
        }
        $rate = app(RatePlanService::class)->create([
            'rate_code' => 'R1-VALID', 'rate_name' => 'Valid', 'plan_type' => 'nightly',
            'base_rate' => '100.00',
        ]);
        $this->expectInvalidDirectInput(fn () => app(RatePlanService::class)->create([
            'rate_code' => 'R1-VALID', 'rate_name' => 'Duplicate', 'plan_type' => 'nightly',
            'base_rate' => '100.00',
        ]));
        $this->expectInvalidDirectInput(fn () => app(RatePlanService::class)
            ->update($rate->id, ['base_rate' => '100000000.00']));
        $room = $this->makePmsRoom($property);
        $block = app(RoomBlockService::class)->create([
            'room_id' => $room->id, 'block_type' => 'out_of_order',
            'start_at' => '2026-12-01T10:00', 'end_at' => '2026-12-02T10:00',
        ]);
        $this->expectInvalidDirectInput(fn () => app(RoomBlockService::class)
            ->update($block->id, ['end_at' => '2026-11-30T10:00']));
        $this->assertSame('2026-12-02 10:00:00', $block->fresh()->end_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('room_blocks')->where('property_id', $property->id)->count());
        $this->assertSame(1, DB::table('rate_plans')->where('property_id', $property->id)->count());
    }

    public function test_malformed_cross_property_guest_pivot_is_never_repaired_or_mutated(): void
    {
        [$property, , $primary] = $this->fixture();
        $otherProperty = $this->createProperty($this->createCompany());
        $otherGuest = app(GuestService::class)->create(['full_name' => 'Second Guest']);
        $reservation = app(ReservationService::class)->create($this->input($primary->id));
        DB::table('reservation_guests')->insert([
            'id' => (string) Str::ulid(), 'property_id' => $otherProperty->id,
            'reservation_id' => $reservation->id, 'guest_id' => $otherGuest->id,
            'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDenied(fn () => app(ReservationService::class)
            ->update($reservation->id, ['primary_guest_id' => $otherGuest->id]), 409);
        $this->assertDenied(fn () => app(GuestService::class)
            ->addAccompanyingGuest($reservation->id, $otherGuest->id), 409);
        $this->assertDenied(fn () => app(GuestService::class)
            ->removeAccompanyingGuest($reservation->id, $otherGuest->id), 409);
        $this->assertSame($primary->id, $reservation->fresh()->primary_guest_id);
        $this->assertSame(1, DB::table('reservation_guests')->where('property_id', $otherProperty->id)
            ->where('reservation_id', $reservation->id)->count());
        $this->assertSame(0, AuditLog::where('auditable_id', $reservation->id)
            ->whereIn('event', ['pms_reservation_amended', 'pms_reservation_guest_linked',
                'pms_reservation_guest_unlinked'])->count());
    }

    private function expectInvalidDirectInput(callable $call): void
    {
        try {
            $call();
            $this->fail('Invalid direct service input was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_r2_historical_guest_relationship_conflicts_fail_closed_with_legacy_pivots_preserved(): void
    {
        [$property, $actor, $primary] = $this->fixture();
        $foreign = $this->makePmsGuest($this->createProperty($this->createCompany()));
        $local = $this->makePmsGuest($property);
        $service = app(ReservationService::class);

        $legacy = $this->makePmsReservation($property, $primary);
        $this->assertSame('legacy', $service->update($legacy->id, ['remarks' => 'legacy'])->remarks);
        app(GuestService::class)->addAccompanyingGuest($legacy->id, $local->id);
        $this->assertSame('still valid', $service->update($legacy->id, ['remarks' => 'still valid'])->remarks);

        $foreignPivot = $this->makePmsReservation($property, $primary);
        $this->insertR2Pivot($property->id, $foreignPivot->id, $foreign->id, false);
        $this->assertDenied(fn () => $service->update($foreignPivot->id, ['remarks' => 'bad']), 409);
        $this->assertDenied(fn () => app(GuestService::class)
            ->addAccompanyingGuest($foreignPivot->id, $local->id), 409);
        $this->assertDenied(fn () => app(GuestLedgerFolioAggregateService::class)
            ->openWindow($actor, $foreignPivot->id, 'r2-foreign-pivot'), 409);
        $this->assertSame(0, DB::table('folios')->where('reservation_id', $foreignPivot->id)->count());

        $deletedPivot = $this->makePmsReservation($property, $primary);
        $deleted = $this->makePmsGuest($property);
        $this->insertR2Pivot($property->id, $deletedPivot->id, $deleted->id, false);
        DB::table('guests')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        $this->assertDenied(fn () => $service->update($deletedPivot->id, ['remarks' => 'bad']), 409);

        $multiple = $this->makePmsReservation($property, $primary);
        $this->insertR2Pivot($property->id, $multiple->id, $primary->id, true);
        $this->insertR2Pivot($property->id, $multiple->id, $local->id, true);
        $this->assertDenied(fn () => $service->update($multiple->id, ['remarks' => 'bad']), 409);

        $contradictory = $this->makePmsReservation($property, $primary);
        $this->insertR2Pivot($property->id, $contradictory->id, $local->id, true);
        $this->assertDenied(fn () => $service->update($contradictory->id, ['remarks' => 'bad']), 409);

        $foreignPrimary = $this->makePmsReservation($property, $primary);
        DB::table('reservations')->where('id', $foreignPrimary->id)
            ->update(['primary_guest_id' => $foreign->id]);
        $this->assertDenied(fn () => $service->update($foreignPrimary->id, ['remarks' => 'bad']), 409);
        foreach ([$foreignPivot, $deletedPivot, $multiple, $contradictory, $foreignPrimary] as $blocked) {
            $this->assertNull($blocked->fresh()->remarks);
            $this->assertSame(0, AuditLog::where('auditable_id', $blocked->id)
                ->where('event', 'pms_reservation_amended')->count());
        }
    }

    public function test_r2_room_block_merged_window_and_rate_plan_reference_remain_atomic(): void
    {
        [$property, , $guest] = $this->fixture();
        $room = $this->makePmsRoom($property);
        $blocks = app(RoomBlockService::class);
        $first = $blocks->create(['room_id' => $room->id, 'block_type' => 'out_of_order',
            'start_at' => '2026-12-10 10:00:00', 'end_at' => '2026-12-11 10:00:00']);
        $second = $blocks->create(['room_id' => $room->id, 'block_type' => 'out_of_order',
            'start_at' => '2026-12-12 10:00:00', 'end_at' => '2026-12-13 10:00:00']);
        $this->expectInvalidDirectInput(fn () => $blocks->update($first->id, ['end_at' => '2026-12-12 11:00:00']));
        $this->assertSame('2026-12-11 10:00:00', $first->fresh()->end_at->toDateTimeString());
        $this->assertSame('released', $blocks->release($second->id)->status->value);
        $this->assertSame('expired', $blocks->expire($first->id)->status->value);
        $this->expectInvalidDirectInput(fn () => $blocks->release($first->id));

        $rate = $this->makePmsRatePlan($property);
        $reservation = app(ReservationService::class)->create($this->input($guest->id)
            + ['rate_plan_id' => $rate->id]);
        $this->expectInvalidDirectInput(fn () => app(RatePlanService::class)->delete($rate->id));
        $this->assertNull($rate->fresh()->deleted_at);
        $this->assertSame($rate->id, $reservation->fresh()->rate_plan_id);
        $historicalRate = $this->makePmsRatePlan($property);
        $historical = $this->makePmsReservation($property, $guest,
            ['rate_plan_id' => $historicalRate->id]);
        $historical->delete();
        $this->expectInvalidDirectInput(fn () => app(RatePlanService::class)->delete($historicalRate->id));
        $unused = $this->makePmsRatePlan($property);
        $this->assertTrue(app(RatePlanService::class)->delete($unused->id));
    }

    private function insertR2Pivot(string $propertyId, string $reservationId, string $guestId, bool $primary): void
    {
        DB::table('reservation_guests')->insert([
            'id' => (string) Str::ulid(), 'property_id' => $propertyId,
            'reservation_id' => $reservationId, 'guest_id' => $guestId,
            'is_primary' => $primary, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
