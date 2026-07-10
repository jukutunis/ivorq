<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use ManagesConcurrencyDatabase;

    protected string $stayId;
    protected string $frontDeskActorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConcurrencyDatabase('ivorq_concurrency_fd_b5_', '2026-07-10 10:00:00');

        $this->seedConcurrencyDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownConcurrencyDatabase();
        parent::tearDown();
    }

    public function test_duplicate_idempotency_key_concurrency(): void
    {
        $key = 'concurrent-key-' . Str::ulid();

        [$firstPid, $secondPid] = $this->simultaneousCreates($this->stayId, 'CHECKOUT_ELIGIBLE', 'Concurrent.', $key, $key);

        $this->assertNotNull($firstPid);
        $this->assertNotNull($secondPid);
        $this->assertSame(1, $this->countEligibilities($this->stayId));

        $e = $this->findByKey($key);
        $this->assertNotNull($e);
        $this->assertSame('IN_HOUSE', $this->findStay($this->stayId)->status->value);
    }

    public function test_distinct_keys_concurrency(): void
    {
        $key1 = 'distinct-1-' . Str::ulid();
        $key2 = 'distinct-2-' . Str::ulid();

        [$firstPid, $secondPid] = $this->simultaneousDistinctCreates(
            $this->stayId,
            ['status' => 'CHECKOUT_ELIGIBLE', 'note' => 'A.', 'key' => $key1],
            ['status' => 'CHECKOUT_BLOCKED', 'note' => 'B.', 'key' => $key2],
        );

        $this->assertNotNull($firstPid);
        $this->assertNotNull($secondPid);
        $this->assertSame(2, $this->countEligibilities($this->stayId));
    }

    private function seedConcurrencyDatabase(): void
    {
        DB::setDefaultConnection('pgsql_concurrency');
        $this->setUpFrontDeskFdA2Fixture();
        $stay = $this->checkedInStay('5601');

        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'OPERATIONAL_HANDOVER_READY', null, 'doh-seed-' . Str::ulid()
        );
        app(FrontDeskDepartureClosureReadinessService::class)->create(
            $this->frontDeskActor, $stay[0]->id, 'CLOSURE_READY', null, 'dcr-seed-' . Str::ulid()
        );

        $this->stayId = $stay[0]->id;
        $this->frontDeskActorId = $this->frontDeskActor->id;
        DB::setDefaultConnection('pgsql');
    }

    private function simultaneousCreates(string $stayId, string $status, string $note, string $keyA, string $keyB): array
    {
        $actorId = $this->frontDeskActorId;
        $create = function (string $key) use ($stayId, $status, $note, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;
            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);
                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()->whereKey($actorId)->first();
                app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
                    $actor, $stayId, $status, $note, $key
                );
            } catch (DomainException) {
                // expected for duplicate idempotency
            } finally {
                DB::setDefaultConnection('pgsql'); config(['database.default' => 'pgsql']);
            }
            return (int) $pid;
        };
        return [$create($keyA), $create($keyB)];
    }

    private function simultaneousDistinctCreates(string $stayId, array $first, array $second): array
    {
        $actorId = $this->frontDeskActorId;
        $create = function (array $params) use ($stayId, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;
            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);
                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()->whereKey($actorId)->first();
                app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
                    $actor, $stayId, $params['status'], $params['note'] ?? null, $params['key']
                );
            } catch (DomainException) {
                // expected when another worker creates the same record
            } finally {
                DB::setDefaultConnection('pgsql'); config(['database.default' => 'pgsql']);
            }
            return (int) $pid;
        };
        return [$create($first), $create($second)];
    }

    private function countEligibilities(string $stayId): int
    {
        return FrontDeskDepartureCheckoutEligibility::on('pgsql_concurrency')
            ->withoutGlobalScopes()->where('front_desk_stay_id', $stayId)->count();
    }

    private function findByKey(string $key): ?FrontDeskDepartureCheckoutEligibility
    {
        return FrontDeskDepartureCheckoutEligibility::on('pgsql_concurrency')
            ->withoutGlobalScopes()->where('idempotency_key', $key)->first();
    }

    private function findStay(string $stayId): FrontDeskStay
    {
        return FrontDeskStay::on('pgsql_concurrency')->withoutGlobalScopes()->whereKey($stayId)->firstOrFail();
    }
}
