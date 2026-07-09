<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskDepartureCheckoutEligibilityIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;

    protected string $concurrencyDb;
    protected string $stayId;
    protected string $frontDeskActorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->concurrencyDb = 'ivorq_concurrency_fd_b5_' . Str::lower(Str::random(8));
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
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00'));

        $this->artisan('migrate', ['--database' => 'pgsql_concurrency', '--force' => true]);
        $this->seedConcurrencyDatabase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('pgsql_concurrency');
        DB::statement("SELECT pg_terminate_backend(pg_stat_activity.pid)
            FROM pg_stat_activity WHERE pg_stat_activity.datname = '{$this->concurrencyDb}' AND pid <> pg_backend_pid()");
        DB::statement("DROP DATABASE IF EXISTS \"{$this->concurrencyDb}\"");
        parent::tearDown();
    }

    public function test_duplicate_idempotency_key_concurrency(): void
    {
        $key = 'concurrent-key-' . Str::ulid();

        $this->exposeOsAndPgPids();
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

        $this->exposeOsAndPgPids();
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

    private function exposeOsAndPgPids(): void
    {
        fwrite(STDERR, 'OS PID: ' . getmypid() . PHP_EOL);
        $pgBackend = DB::connection('pgsql_concurrency')->select('SELECT pg_backend_pid() as pid');
        fwrite(STDERR, 'PG Backend PID: ' . $pgBackend[0]->pid . PHP_EOL);
    }

    private function simultaneousCreates(string $stayId, string $status, string $note, string $keyA, string $keyB): array
    {
        $actorId = $this->frontDeskActorId;
        $create = function (string $worker, string $key) use ($stayId, $status, $note, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;
            fwrite(STDERR, "Worker {$worker} PG PID: {$pid}" . PHP_EOL);
            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);
                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()->whereKey($actorId)->first();
                app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
                    $actor, $stayId, $status, $note, $key
                );
            } catch (DomainException $e) {
                fwrite(STDERR, "Worker {$worker} domain error: {$e->getMessage()}" . PHP_EOL);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Worker {$worker} error: {$e->getMessage()}" . PHP_EOL);
            } finally {
                DB::setDefaultConnection('pgsql'); config(['database.default' => 'pgsql']);
            }
            return (int) $pid;
        };
        return [$create('A', $keyA), $create('B', $keyB)];
    }

    private function simultaneousDistinctCreates(string $stayId, array $first, array $second): array
    {
        $actorId = $this->frontDeskActorId;
        $create = function (string $worker, array $params) use ($stayId, $actorId): int {
            $pgsql = DB::connection('pgsql_concurrency');
            $pid = $pgsql->select('SELECT pg_backend_pid() as pid')[0]->pid;
            fwrite(STDERR, "Worker {$worker} PG PID: {$pid}" . PHP_EOL);
            try {
                DB::setDefaultConnection('pgsql_concurrency');
                config(['database.default' => 'pgsql_concurrency']);
                $actor = \Modules\Foundation\User\Models\User::withoutGlobalScopes()->whereKey($actorId)->first();
                app(FrontDeskDepartureCheckoutEligibilityService::class)->create(
                    $actor, $stayId, $params['status'], $params['note'] ?? null, $params['key']
                );
            } catch (DomainException $e) {
                fwrite(STDERR, "Worker {$worker} domain error: {$e->getMessage()}" . PHP_EOL);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Worker {$worker} error: {$e->getMessage()}" . PHP_EOL);
            } finally {
                DB::setDefaultConnection('pgsql'); config(['database.default' => 'pgsql']);
            }
            return (int) $pid;
        };
        return [$create('A', $first), $create('B', $second)];
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
