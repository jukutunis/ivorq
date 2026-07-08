<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDeparturePreparationEvent;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationEventImmutabilityTest extends PostgresTestCase
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

    // ── UPDATED_AT is null ──

    public function test_updated_at_is_null(): void
    {
        $this->assertNull((new FrontDeskDeparturePreparationEvent())->UPDATED_AT);
    }

    // ── Application-level update blocked ──

    public function test_application_update_blocked(): void
    {
        [$stay] = $this->checkedInStay('7001');

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'Original note', 'dpe-' . Str::ulid()
        );

        $event = $result['event'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $event->note = 'Modified note';
        $event->save();
    }

    // ── Application-level delete blocked ──

    public function test_application_delete_blocked(): void
    {
        [$stay] = $this->checkedInStay('7002');

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'Note to delete', 'dpe-' . Str::ulid()
        );

        $event = $result['event'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $event->delete();
    }

    // ── PostgreSQL UPDATE trigger blocks mutation ──

    public function test_postgresql_update_trigger_blocks_mutation(): void
    {
        [$stay] = $this->checkedInStay('7003');

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'PG update test', 'dpe-' . Str::ulid()
        );

        $event = $result['event'];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::statement("UPDATE front_desk_departure_preparation_events SET note = 'hacked' WHERE id = ?", [$event->id]);
    }

    // ── PostgreSQL DELETE trigger blocks deletion ──

    public function test_postgresql_delete_trigger_blocks_deletion(): void
    {
        [$stay] = $this->checkedInStay('7004');

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'PG delete test', 'dpe-' . Str::ulid()
        );

        $event = $result['event'];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::statement('DELETE FROM front_desk_departure_preparation_events WHERE id = ?', [$event->id]);
    }

    // ── Event count unchanged after blocked mutation attempt ──

    public function test_event_count_unchanged_after_blocked_mutation(): void
    {
        [$stay] = $this->checkedInStay('7005');

        $result = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->frontDeskActor, $stay->id,
            'DEPARTURE_NOTE_RECORDED', 'Count test', 'dpe-' . Str::ulid()
        );

        $event = $result['event'];
        $countBefore = FrontDeskDeparturePreparationEvent::withoutGlobalScopes()->count();

        try {
            $event->note = 'Modified';
            $event->save();
        } catch (DomainException) {
        }

        $countAfter = FrontDeskDeparturePreparationEvent::withoutGlobalScopes()->count();
        $this->assertSame($countBefore, $countAfter);

        $persisted = FrontDeskDeparturePreparationEvent::withoutGlobalScopes()->find($event->id);
        $this->assertSame('Count test', $persisted->note);
    }
}
