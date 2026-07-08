<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationEventAuthorizationTest extends PostgresTestCase
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

    // ── Unauthenticated denied ──

    public function test_unauthenticated_denied(): void
    {
        [$stay] = $this->checkedInStay('4001');

        $this->withSession($this->propertySession($this->property))
            ->post("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ])
            ->assertRedirect();
    }

    // ── Exact permission required ──

    public function test_event_create_permission_required(): void
    {
        [$stay] = $this->checkedInStay('4002');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskViewOnlyActor, 'web')
            ->post("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    public function test_view_permission_alone_cannot_create_event(): void
    {
        [$stay] = $this->checkedInStay('4003');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskViewOnlyActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    public function test_front_desk_actor_with_create_permission_can_create(): void
    {
        [$stay] = $this->checkedInStay('4004');

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'note' => 'Test note via HTTP.',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ]);

        $response->assertOk();
        $response->assertJsonFragment(['event_type' => 'DEPARTURE_NOTE_RECORDED']);
        $response->assertJsonFragment(['replayed' => false]);
    }

    // ── Finance actor cannot create event ──

    public function test_finance_actor_cannot_create_event(): void
    {
        [$stay] = $this->checkedInStay('4005');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->financeActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    // ── Engineering actor cannot create event ──

    public function test_engineering_actor_cannot_create_event(): void
    {
        [$stay] = $this->checkedInStay('4006');

        $this->withSession($this->propertySession($this->property))
            ->actingAs($this->engineeringActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    // ── Active property required ──

    public function test_active_property_required(): void
    {
        [$stay] = $this->checkedInStay('4007');

        $this->flushSession();

        $this->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
            ])
            ->assertForbidden();
    }

    // ── Browser cannot control server-owned fields ──

    public function test_browser_cannot_control_property_id(): void
    {
        [$stay] = $this->checkedInStay('4008');

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
                'property_id' => $this->otherProperty->id,
            ]);

        $response->assertOk();
        $eventId = $response->json('event_id');
        $event = \Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent::find($eventId);
        $this->assertSame($this->property->id, $event->property_id);
        $this->assertNotSame($this->otherProperty->id, $event->property_id);
    }

    public function test_browser_cannot_control_occurred_at(): void
    {
        [$stay] = $this->checkedInStay('4009');

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
                'occurred_at' => '2020-01-01T00:00:00Z',
            ]);

        $response->assertOk();
        $eventId = $response->json('event_id');
        $event = \Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent::find($eventId);
        $this->assertNotSame('2020-01-01T00:00:00.000000Z', $event->occurred_at?->toISOString());
    }

    public function test_browser_cannot_control_created_by(): void
    {
        [$stay] = $this->checkedInStay('4010');

        $response = $this->withSession($this->propertySession($this->property))
            ->actingAs($this->frontDeskActor, 'web')
            ->postJson("/frontdesk/stays/{$stay->id}/departure-preparation-events", [
                'event_type' => 'DEPARTURE_NOTE_RECORDED',
                'idempotency_key' => 'dpe-' . Str::ulid(),
                'created_by' => $this->financeActor->id,
            ]);

        $response->assertOk();
        $eventId = $response->json('event_id');
        $event = \Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent::find($eventId);
        $this->assertSame($this->frontDeskActor->id, $event->created_by);
    }
}
