<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutAuthorizationIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use ManagesConcurrencyDatabase;

    protected string $stayId;
    protected string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConcurrencyDatabase('ivorq_concurrency_fd_b6_', '2026-07-10 11:00:00');

        $this->seedB6Data();
    }

    protected function tearDown(): void
    {
        $this->tearDownConcurrencyDatabase();
        parent::tearDown();
    }

    private function seedB6Data(): void
    {
        DB::setDefaultConnection('pgsql_concurrency');
        $this->setUpFrontDeskFdA2Fixture();
        $s = $this->checkedInStay('6601');
        app(FrontDeskDepartureOperationalHandoverService::class)->create($this->frontDeskActor, $s[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-seed-' . Str::ulid());
        app(FrontDeskDepartureClosureReadinessService::class)->create($this->frontDeskActor, $s[0]->id, 'CLOSURE_READY', null, 'dcr-seed-' . Str::ulid());
        app(FrontDeskDepartureCheckoutEligibilityService::class)->create($this->frontDeskActor, $s[0]->id, 'CHECKOUT_ELIGIBLE', null, 'dce-seed-' . Str::ulid());
        $this->stayId = $s[0]->id;
        $this->actorId = $this->frontDeskActor->id;
        DB::setDefaultConnection('pgsql');
    }

    public function test_idempotent_concurrency(): void
    {
        $k = 'concurrent-' . Str::ulid();
        $this->simCreate('A', $k, 'CHECKOUT_AUTHORIZATION_READY');
        $this->simCreate('B', $k, 'CHECKOUT_AUTHORIZATION_READY');
        $this->assertSame(1, FrontDeskDepartureCheckoutAuthorization::on('pgsql_concurrency')->withoutGlobalScopes()->where('front_desk_stay_id', $this->stayId)->count());
    }

    public function test_distinct_concurrency(): void
    {
        $k1 = 'dist-1-' . Str::ulid();
        $k2 = 'dist-2-' . Str::ulid();
        $this->simCreate('A', $k1, 'CHECKOUT_AUTHORIZATION_READY');
        $this->simCreate('B', $k2, 'CHECKOUT_AUTHORIZATION_BLOCKED');
        $this->assertSame(2, FrontDeskDepartureCheckoutAuthorization::on('pgsql_concurrency')->withoutGlobalScopes()->where('front_desk_stay_id', $this->stayId)->count());
    }

    private function simCreate(string $w, string $k, string $s): void
    {
        $p = DB::connection('pgsql_concurrency');
        $pid = $p->select('SELECT pg_backend_pid() as pid')[0]->pid;
        try {
            DB::setDefaultConnection('pgsql_concurrency');
            config(['database.default' => 'pgsql_concurrency']);
            $a = \Modules\Foundation\User\Models\User::withoutGlobalScopes()->whereKey($this->actorId)->first();
            app(FrontDeskDepartureCheckoutAuthorizationService::class)->create($a, $this->stayId, $s, null, $k);
        } catch (DomainException) {
            // expected for duplicate idempotency
        } finally {
            DB::setDefaultConnection('pgsql');
            config(['database.default' => 'pgsql']);
        }
    }
}
