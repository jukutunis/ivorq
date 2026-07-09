<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityImmutabilityTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));
        $this->setUpFrontDeskFdA2Fixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createEligibility(): FrontDeskDepartureCheckoutEligibility
    {
        $stay = $this->checkedInStay('5401');
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-' . Str::ulid()
        );
        $result = app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-' . Str::ulid()
        );
        return $result['eligibility'];
    }

    public function test_application_update_blocked(): void
    {
        $e = $this->createEligibility();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');
        $e->eligibility_note = 'Mutation';
        $e->save();
    }

    public function test_application_delete_blocked(): void
    {
        $e = $this->createEligibility();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');
        $e->delete();
    }

    public function test_postgresql_update_blocked(): void
    {
        $e = $this->createEligibility();
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('front_desk_departure_checkout_eligibilities')
            ->where('id', $e->id)
            ->update(['eligibility_note' => 'Direct SQL']);
    }

    public function test_postgresql_delete_blocked(): void
    {
        $e = $this->createEligibility();
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('front_desk_departure_checkout_eligibilities')
            ->where('id', $e->id)
            ->delete();
    }
}
