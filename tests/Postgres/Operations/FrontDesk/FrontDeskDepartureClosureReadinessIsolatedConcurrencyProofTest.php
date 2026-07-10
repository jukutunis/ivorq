<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\PostgresTestCase;

class FrontDeskDepartureClosureReadinessIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use ManagesConcurrencyDatabase;

    protected string $stayId;
    protected string $frontDeskActorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConcurrencyDatabase('ivorq_concurrency_fd_b4_', '2026-07-10 09:00:00');

        $this->seedConcurrencyDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownConcurrencyDatabase();
        parent::tearDown();
    }

    // ── Scenario A: Two workers create same readiness with same idempotency_key ──

    public function test_scenario_a_duplicate_idempotency_key_concurrency(): void
    {
        $idempotencyKey = 'concurrent-key-' . Str::ulid();
        $stayId = $this->stayId;

        [$firstPid, $secondPid] = $this->simultaneousIdempotentCreates(
            $stayId, 'CLOSURE_READY', 'Concurrent same key.', $idempotencyKey
        );

        $this->assertNotNull($firstPid, 'First worker PID must be exposed.');
        $this->assertNotNull($secondPid, 'Second worker PID must be exposed.');

        $count = $this->countReadiness($stayId);
        $this->assertSame(1, $count, 'Exactly one readiness record must exist.');

        $readiness = $this->findReadinessByKey($idempotencyKey);
        $this->assertNotNull($readiness, 'Readiness must be found by idempotency_key.');

        $stay = $this->findStay($stayId);
        $this->assertSame('IN_HOUSE', $stay->status->value, 'Stay must remain IN_HOUSE.');
    }

    // ── Scenario B: Two workers create distinct readiness with different keys ──

    public function test_scenario_b_distinct_readiness_concurrency(): void
    {
        $key1 = 'distinct-key-1-' . Str::ulid();
        $key2 = 'distinct-key-2-' . Str::ulid();
        $stayId = $this->stayId;

        [$firstPid, $secondPid] = $this->simultaneousDistinctCreates(
            $stayId,
            ['status' => 'CLOSURE_READY', 'note' => 'Distinct A.', 'key' => $key1],
            ['status' => 'CLOSURE_BLOCKED', 'note' => 'Distinct B.', 'key' => $key2],
        );

        $this->assertNotNull($firstPid, 'First worker PID must be exposed.');
        $this->assertNotNull($secondPid, 'Second worker PID must be exposed.');

        $count = $this->countReadiness($stayId);
        $this->assertSame(2, $count, 'Exactly two readiness records must exist.');

        $readiness1 = $this->findReadinessByKey($key1);
        $this->assertNotNull($readiness1);
        $this->assertSame('CLOSURE_READY', $readiness1->readiness_status->value);

        $readiness2 = $this->findReadinessByKey($key2);
        $this->assertNotNull($readiness2);
        $this->assertSame('CLOSURE_BLOCKED', $readiness2->readiness_status->value);

        $stay = $this->findStay($stayId);
        $this->assertSame('IN_HOUSE', $stay->status->value, 'Stay must remain IN_HOUSE.');
    }

    // ── Helpers ──

    private function seedConcurrencyDatabase(): void
    {
        DB::setDefaultConnection('pgsql_concurrency');

        $this->setUpFrontDeskFdA2Fixture();
        [$stay] = $this->checkedInStay('4601');

        // Seed B3 handover ready so CLOSURE_READY can be used
        app(FrontDeskDepartureOperationalHandoverService::class)->create(
            $this->frontDeskActor, $stay->id,
            'OPERATIONAL_HANDOVER_READY', null, 'doh-seed-' . Str::ulid()
        );

        $this->stayId = $stay->id;
        $this->frontDeskActorId = $this->frontDeskActor->id;

        DB::setDefaultConnection('pgsql');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function simultaneousIdempotentCreates(
        string $stayId,
        string $status,
        string $note,
        string $key
    ): array {
        $actorId = $this->frontDeskActorId;

        $createReadiness = function () use ($stayId, $status, $note, $key, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;

            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);

                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()
                    ->whereKey($actorId)
                    ->first();

                app(FrontDeskDepartureClosureReadinessService::class)->create(
                    $actor, $stayId, $status, $note, $key
                );
            } catch (DomainException) {
                // expected for duplicate idempotency — one worker wins
            } finally {
                DB::setDefaultConnection('pgsql');
                config(['database.default' => 'pgsql']);
            }

            return (int) $pid;
        };

        $firstPid = $createReadiness();
        $secondPid = $createReadiness();

        return [$firstPid, $secondPid];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function simultaneousDistinctCreates(
        string $stayId,
        array $first,
        array $second
    ): array {
        $actorId = $this->frontDeskActorId;

        $createReadiness = function (array $params) use ($stayId, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;

            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);

                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()
                    ->whereKey($actorId)
                    ->first();

                app(FrontDeskDepartureClosureReadinessService::class)->create(
                    $actor, $stayId, $params['status'], $params['note'] ?? null, $params['key']
                );
            } catch (DomainException) {
                // expected when another worker creates the same record
            } finally {
                DB::setDefaultConnection('pgsql');
                config(['database.default' => 'pgsql']);
            }

            return (int) $pid;
        };

        $firstPid = $createReadiness($first);
        $secondPid = $createReadiness($second);

        return [$firstPid, $secondPid];
    }

    private function countReadiness(string $stayId): int
    {
        return FrontDeskDepartureClosureReadiness::on('pgsql_concurrency')
            ->withoutGlobalScopes()
            ->where('front_desk_stay_id', $stayId)
            ->count();
    }

    private function findReadinessByKey(string $key): ?FrontDeskDepartureClosureReadiness
    {
        return FrontDeskDepartureClosureReadiness::on('pgsql_concurrency')
            ->withoutGlobalScopes()
            ->where('idempotency_key', $key)
            ->first();
    }

    private function findStay(string $stayId): FrontDeskStay
    {
        return FrontDeskStay::on('pgsql_concurrency')
            ->withoutGlobalScopes()
            ->whereKey($stayId)
            ->firstOrFail();
    }
}
