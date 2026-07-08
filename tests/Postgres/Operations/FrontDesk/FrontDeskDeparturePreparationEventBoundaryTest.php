<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationEventBoundaryTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── No final checkout state ──

    public function test_no_checked_out_state_in_stay_enum(): void
    {
        $cases = \Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum::cases();
        $values = array_map(fn ($case) => $case->value, $cases);

        $this->assertNotContains('CHECKED_OUT', $values);
        $this->assertNotContains('SETTLED', $values);
        $this->assertNotContains('DEPARTED', $values);
        $this->assertNotContains('CANCELLED', $values);
        $this->assertNotContains('NO_SHOW', $values);
    }

    // ── No checkout/folio/payment routes ──

    public function test_no_checkout_execution_or_financial_routes_exist(): void
    {
        [$stay] = $this->checkedInStay('6001');

        $forbiddenRoutes = [
            "/frontdesk/stays/{$stay->id}/check-out",
            "/frontdesk/stays/{$stay->id}/settle",
            "/frontdesk/stays/{$stay->id}/folio",
            "/frontdesk/stays/{$stay->id}/payment",
            "/frontdesk/stays/{$stay->id}/invoice",
            "/frontdesk/stays/{$stay->id}/receipt",
        ];

        foreach ($forbiddenRoutes as $route) {
            $this->withSession($this->propertySession($this->property))
                ->actingAs($this->frontDeskActor, 'web')
                ->post($route)
                ->assertNotFound();
        }
    }

    // ── No financial fields on model ──

    public function test_event_model_has_no_financial_fields(): void
    {
        $event = new FrontDeskDeparturePreparationEvent();
        $fillable = $event->getFillable();

        $forbiddenFields = [
            'amount', 'currency', 'balance', 'folio_id', 'payment_id',
            'invoice_id', 'tax_id', 'revenue_id', 'gl_account_id',
            'ar_account_id', 'business_date_id', 'financial_period_id',
            'night_audit_id', 'settlement_status', 'paid_status',
            'checkout_status',
        ];

        foreach ($forbiddenFields as $field) {
            $this->assertNotContains($field, $fillable,
                "Field '{$field}' must not exist on departure preparation event.");
        }
    }

    // ── Event creation does not create financial records ──

    public function test_event_creation_does_not_create_financial_records(): void
    {
        [$stay] = $this->checkedInStay('6002');
        $before = $this->domainTableCounts();

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = $this->domainTableCounts();
        $this->assertSame($before, $after);
    }

    // ── No Housekeeping mutation ──

    public function test_event_creation_does_not_mutate_housekeeping_readiness(): void
    {
        [$stay, $roomId] = $this->checkedInStay('6003');
        $before = DB::table('rooms')->where('id', $roomId)->value('readiness_state');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('rooms')->where('id', $roomId)->value('readiness_state');
        $this->assertSame($before, $after);
    }

    // ── No Engineering mutation ──

    public function test_event_creation_does_not_mutate_engineering_availability(): void
    {
        [$stay, $roomId] = $this->checkedInStay('6004');
        $before = DB::table('engineering_room_availability_blocks')
            ->where('room_id', $roomId)->count();

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'TRANSPORTATION_NOTED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('engineering_room_availability_blocks')
            ->where('room_id', $roomId)->count();
        $this->assertSame($before, $after);
    }

    // ── No Room master mutation ──

    public function test_event_creation_does_not_mutate_room_master(): void
    {
        [$stay, $roomId] = $this->checkedInStay('6005');
        $before = DB::table('rooms')->where('id', $roomId)->value('room_number');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'LUGGAGE_ASSISTANCE_NOTED', null, 'dpe-' . Str::ulid()
        );

        $after = DB::table('rooms')->where('id', $roomId)->value('room_number');
        $this->assertSame($before, $after);
    }

    // ── Stay status remains IN_HOUSE after event creation ──

    public function test_stay_status_remains_in_house_after_event(): void
    {
        [$stay] = $this->checkedInStay('6006');
        $stayId = $stay->id;
        $before = DB::table('front_desk_stays')->where('id', $stayId)->value('status');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_BLOCKER_ACKNOWLEDGED', 'Acknowledged housekeeping delay', 'dpe-' . Str::ulid()
        );

        $after = DB::table('front_desk_stays')->where('id', $stayId)->value('status');
        $this->assertSame($before, $after);
        $this->assertSame('IN_HOUSE', $after);
    }

    // ── No Reservation or Guest mutation ──

    public function test_event_creation_does_not_mutate_reservation(): void
    {
        [$stay] = $this->checkedInStay('6007');
        $before = DB::table('reservations')->where('id', $stay->reservation_id)->value('status');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'GUEST_MESSAGE_NOTED', 'Message', 'dpe-' . Str::ulid()
        );

        $after = DB::table('reservations')->where('id', $stay->reservation_id)->value('status');
        $this->assertSame($before, $after);
    }

    public function test_event_creation_does_not_mutate_guest(): void
    {
        [$stay] = $this->checkedInStay('6008');
        $before = DB::table('guests')->where('id', $stay->guest_id)->value('full_name');

        app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_TIME_CONFIRMED', '10:00', 'dpe-' . Str::ulid()
        );

        $after = DB::table('guests')->where('id', $stay->guest_id)->value('full_name');
        $this->assertSame($before, $after);
    }
}
