<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureClosureReadinessImmutabilityTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Application update blocked ──

    public function test_application_update_blocked(): void
    {
        [$stay] = $this->checkedInStay('4401');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $readiness = $result['readiness'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $readiness->readiness_note = 'Attempted mutation';
        $readiness->save();
    }

    // ── Application delete blocked ──

    public function test_application_delete_blocked(): void
    {
        [$stay] = $this->checkedInStay('4402');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $readiness = $result['readiness'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $readiness->delete();
    }

    // ── PostgreSQL update blocked ──

    public function test_postgresql_update_blocked(): void
    {
        [$stay] = $this->checkedInStay('4403');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $readiness = $result['readiness'];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('front_desk_departure_closure_readiness')
            ->where('id', $readiness->id)
            ->update(['readiness_note' => 'Direct SQL mutation']);
    }

    // ── PostgreSQL delete blocked ──

    public function test_postgresql_delete_blocked(): void
    {
        [$stay] = $this->checkedInStay('4404');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $readiness = $result['readiness'];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('front_desk_departure_closure_readiness')
            ->where('id', $readiness->id)
            ->delete();
    }

    // ── UPDATED_AT is null ──

    public function test_updated_at_is_null(): void
    {
        $model = new FrontDeskDepartureClosureReadiness();
        $this->assertNull($model->getUpdatedAtColumn());
        $this->assertNull($model->updated_at ?? null);
    }

    // ── No updated_at in table ──

    public function test_no_updated_at_column_in_table(): void
    {
        [$stay] = $this->checkedInStay('4405');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $result = app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay->id,
            'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );

        $record = DB::table('front_desk_departure_closure_readiness')
            ->where('id', $result['readiness']->id)
            ->first();

        $this->assertObjectNotHasProperty('updated_at', $record);
    }
}
