<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureClosureReadinessIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;

    protected string $concurrencyDb;
    protected string $stayId;
    protected string $frontDeskActorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->concurrencyDb = 'ivorq_concurrency_fd_b4_' . Str::lower(Str::random(8));
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

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));

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

    // ── Scenario A: Two workers create same readiness with same idempotency_key ──

    public function test_scenario_a_duplicate_idempotency_key_concurrency(): void
    {
        $idempotencyKey = 'concurrent-key-' . Str::ulid();
        $stayId = $this->stayId;
        $actorId = $this->frontDeskActorId;

        $this->exposeOsAndPgPids();

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

        $this->exposeOsAndPgPids();

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
        $actorId = $this->frontDeskActorId;

        $createReadiness = function (string $worker) use ($stayId, $status, $note, $key, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;

            fwrite(STDERR, "Worker {$worker} PG PID: {$pid}" . PHP_EOL);

            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);

                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()
                    ->whereKey($actorId)
                    ->first();

                app(FrontDeskDepartureClosureReadinessService::class)->create(
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

        $firstPid = $createReadiness('A');
        $secondPid = $createReadiness('B');

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

        $createReadiness = function (string $worker, array $params) use ($stayId, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;

            fwrite(STDERR, "Worker {$worker} PG PID: {$pid}" . PHP_EOL);

            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);

                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()
                    ->whereKey($actorId)
                    ->first();

                app(FrontDeskDepartureClosureReadinessService::class)->create(
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

        $firstPid = $createReadiness('A', $first);
        $secondPid = $createReadiness('B', $second);

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
