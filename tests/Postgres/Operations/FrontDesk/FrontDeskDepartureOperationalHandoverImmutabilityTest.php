<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureOperationalHandoverImmutabilityTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-09 09:00:00'));
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
        [$stay] = $this->checkedInStay('3401');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $handover = $result['handover'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $handover->handover_note = 'Attempted mutation';
        $handover->save();
    }

    // ── Application delete blocked ──

    public function test_application_delete_blocked(): void
    {
        [$stay] = $this->checkedInStay('3402');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $handover = $result['handover'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $handover->delete();
    }

    // ── PostgreSQL update blocked ──

    public function test_postgresql_update_blocked(): void
    {
        [$stay] = $this->checkedInStay('3403');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $handover = $result['handover'];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('front_desk_departure_operational_handovers')
            ->where('id', $handover->id)
            ->update(['handover_note' => 'Direct SQL mutation']);
    }

    // ── PostgreSQL delete blocked ──

    public function test_postgresql_delete_blocked(): void
    {
        [$stay] = $this->checkedInStay('3404');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $handover = $result['handover'];

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('front_desk_departure_operational_handovers')
            ->where('id', $handover->id)
            ->delete();
    }

    // ── UPDATED_AT is null ──

    public function test_updated_at_is_null(): void
    {
        $model = new FrontDeskDepartureOperationalHandover();
        $this->assertNull($model->getUpdatedAtColumn());
        $this->assertNull($model->updated_at ?? null);
    }

    // ── No updated_at in table ──

    public function test_no_updated_at_column_in_table(): void
    {
        [$stay] = $this->checkedInStay('3405');

        $result = app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );

        $record = DB::table('front_desk_departure_operational_handovers')
            ->where('id', $result['handover']->id)
            ->first();

        $this->assertObjectNotHasProperty('updated_at', $record);
    }
}
