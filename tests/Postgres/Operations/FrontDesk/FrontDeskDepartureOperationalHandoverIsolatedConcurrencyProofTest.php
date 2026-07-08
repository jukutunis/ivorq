<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureOperationalHandoverIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;

    protected string $concurrencyDb;
    protected string $stayId;
    protected string $frontDeskActorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->concurrencyDb = 'ivorq_concurrency_fd_b3_' . Str::lower(Str::random(8));
        DB::statement("CREATE DATABASE \"{$this->concurrencyDb}\"");
        DB::disconnect();

        config(['database.connections.pgsql_concurrency' => [
            'driver' => 'pgsql',
            'host' => config('database.connections.pgsql.host'),
            'port' => config('database.connections.pgsql.port'),
            'database' => $this->concurrencyDb,
            'username' => config('database.connections.pgsql.username'),
            'password' => config('database.connections.pgsql.password'),
        ]]);

        DB::purge('pgsql_concurrency');

        Carbon::setTestNow(Carbon::parse('2026-07-09 09:00:00'));

        $this->artisan('migrate', [
            '--database' => 'pgsql_concurrency',
            '--force' => true,
        ]);

        $this->seedConcurrencyDatabase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('pgsql_concurrency');

        DB::statement("SELECT pg_terminate_backend(pg_stat_activity.pid)
            FROM pg_stat_activity
            WHERE pg_stat_activity.datname = '{$this->concurrencyDb}'
              AND pid <> pg_backend_pid()");

        DB::statement("DROP DATABASE IF EXISTS \"{$this->concurrencyDb}\"");
        parent::tearDown();
    }

    // ── Scenario A: Two workers create same handover with same idempotency_key ──

    public function test_scenario_a_duplicate_idempotency_key_concurrency(): void
    {
        $idempotencyKey = 'concurrent-key-' . Str::ulid();
        $stayId = $this->stayId;
        $actorId = $this->frontDeskActorId;
        $concurrencyDb = $this->concurrencyDb;

        $this->exposeOsAndPgPids();

        [$firstPid, $secondPid] = $this->simultaneousIdempotentCreates(
            $stayId, 'OPERATIONAL_HANDOVER_READY', 'Concurrent same key.', $idempotencyKey
        );

        $this->assertNotNull($firstPid, 'First worker PID must be exposed.');
        $this->assertNotNull($secondPid, 'Second worker PID must be exposed.');

        $count = $this->countHandovers($stayId);
        $this->assertSame(1, $count, 'Exactly one handover record must exist.');

        $handover = $this->findHandoverByKey($idempotencyKey);
        $this->assertNotNull($handover, 'Handover must be found by idempotency_key.');

        $stay = $this->findStay($stayId);
        $this->assertSame('IN_HOUSE', $stay->status->value, 'Stay must remain IN_HOUSE after concurrent handover.');
    }

    // ── Scenario B: Two workers create distinct handovers with different statuses/keys ──

    public function test_scenario_b_distinct_handovers_concurrency(): void
    {
        $key1 = 'distinct-key-1-' . Str::ulid();
        $key2 = 'distinct-key-2-' . Str::ulid();
        $stayId = $this->stayId;

        $this->exposeOsAndPgPids();

        [$firstPid, $secondPid] = $this->simultaneousDistinctCreates(
            $stayId,
            ['status' => 'OPERATIONAL_HANDOVER_READY', 'note' => 'Distinct A.', 'key' => $key1],
            ['status' => 'OPERATIONAL_HANDOVER_BLOCKED', 'note' => 'Distinct B.', 'key' => $key2],
        );

        $this->assertNotNull($firstPid, 'First worker PID must be exposed.');
        $this->assertNotNull($secondPid, 'Second worker PID must be exposed.');

        $count = $this->countHandovers($stayId);
        $this->assertSame(2, $count, 'Exactly two handover records must exist.');

        $handover1 = $this->findHandoverByKey($key1);
        $this->assertNotNull($handover1);
        $this->assertSame('OPERATIONAL_HANDOVER_READY', $handover1->handover_status->value);

        $handover2 = $this->findHandoverByKey($key2);
        $this->assertNotNull($handover2);
        $this->assertSame('OPERATIONAL_HANDOVER_BLOCKED', $handover2->handover_status->value);

        $stay = $this->findStay($stayId);
        $this->assertSame('IN_HOUSE', $stay->status->value, 'Stay must remain IN_HOUSE after concurrent distinct handovers.');
    }

    // ── Helpers ──

    private function seedConcurrencyDatabase(): void
    {
        $pgsql = DB::connection('pgsql_concurrency');

        DB::setDefaultConnection('pgsql_concurrency');

        $this->setUpFrontDeskFdA2Fixture();
        [$stay] = $this->checkedInStay('3601');

        $this->stayId = $stay->id;
        $this->frontDeskActorId = $this->frontDeskActor->id;

        DB::setDefaultConnection('pgsql');
    }

    private function exposeOsAndPgPids(): void
    {
        fwrite(STDERR, 'OS PID: ' . getmypid() . PHP_EOL);

        $pgBackend = DB::connection('pgsql_concurrency')
            ->select('SELECT pg_backend_pid() as pid');
        fwrite(STDERR, 'PG Backend PID: ' . $pgBackend[0]->pid . PHP_EOL);
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
        $concurrencyDb = $this->concurrencyDb;

        $createHandover = function (string $worker) use ($stayId, $status, $note, $key, $concurrencyDb): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;

            fwrite(STDERR, "Worker {$worker} PG PID: {$pid}" . PHP_EOL);

            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);

                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()
                    ->whereKey($this->frontDeskActorId)
                    ->first();

                app(FrontDeskDepartureOperationalHandoverService::class)->create(
                    $actor, $stayId, $status, $note, $key
                );
            } catch (DomainException $e) {
                fwrite(STDERR, "Worker {$worker} domain error: {$e->getMessage()}" . PHP_EOL);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Worker {$worker} error: {$e->getMessage()}" . PHP_EOL);
            } finally {
                DB::setDefaultConnection('pgsql');
                config(['database.default' => 'pgsql']);
            }

            return (int) $pid;
        };

        $firstPid = $createHandover('A');
        $secondPid = $createHandover('B');

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
        $concurrencyDb = $this->concurrencyDb;
        $actorId = $this->frontDeskActorId;

        $createHandover = function (string $worker, array $params) use ($stayId, $concurrencyDb, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;

            fwrite(STDERR, "Worker {$worker} PG PID: {$pid}" . PHP_EOL);

            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);

                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()
                    ->whereKey($actorId)
                    ->first();

                app(FrontDeskDepartureOperationalHandoverService::class)->create(
                    $actor, $stayId, $params['status'], $params['note'] ?? null, $params['key']
                );
            } catch (DomainException $e) {
                fwrite(STDERR, "Worker {$worker} domain error: {$e->getMessage()}" . PHP_EOL);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Worker {$worker} error: {$e->getMessage()}" . PHP_EOL);
            } finally {
                DB::setDefaultConnection('pgsql');
                config(['database.default' => 'pgsql']);
            }

            return (int) $pid;
        };

        $firstPid = $createHandover('A', $first);
        $secondPid = $createHandover('B', $second);

        return [$firstPid, $secondPid];
    }

    private function countHandovers(string $stayId): int
    {
        return FrontDeskDepartureOperationalHandover::on('pgsql_concurrency')
            ->withoutGlobalScopes()
            ->where('front_desk_stay_id', $stayId)
            ->count();
    }

    private function findHandoverByKey(string $key): ?FrontDeskDepartureOperationalHandover
    {
        return FrontDeskDepartureOperationalHandover::on('pgsql_concurrency')
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
