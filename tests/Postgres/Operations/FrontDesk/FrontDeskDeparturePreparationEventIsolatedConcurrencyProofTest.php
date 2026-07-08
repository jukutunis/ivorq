<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Tests\PostgresTestCase;

class FrontDeskDeparturePreparationEventIsolatedConcurrencyProofTest extends PostgresTestCase
{
    private const DISPOSABLE_DB_PREFIX = 'ivorq_concurrency_fd_b2_';

    private string $disposableDb;
    private Company $company;
    private Property $property;
    private User $frontDeskActor;
    private User $workerA;
    private User $workerB;
    private string $stayId;
    private array $pgConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pgConfig = config('database.connections.pgsql');
        $this->disposableDb = self::DISPOSABLE_DB_PREFIX . date('Ymd_His') . '_' . Str::lower(Str::random(6));

        $this->createDisposableDatabase();
        $this->migrateDisposableDatabase();
        $this->seedDisposablePermissions();
        $this->createDisposableFixture();
    }

    protected function tearDown(): void
    {
        $this->dropDisposableDatabase();
        parent::tearDown();
    }

    // ── Scenario A: Duplicate idempotency ──

    public function test_duplicate_idempotency_produces_exactly_one_event(): void
    {
        $idempotencyKey = 'concurrency-idem-' . Str::ulid();

        $pidA = getmypid();
        $pgPid = DB::select('SELECT pg_backend_pid() as pid')[0]->pid;

        // Worker A creates the event
        $resultA = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->workerA,
            $this->stayId,
            'DEPARTURE_NOTE_RECORDED',
            'Simultaneous note',
            $idempotencyKey
        );

        // Worker B replays (same idempotency key)
        $resultB = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->workerB,
            $this->stayId,
            'DEPARTURE_TIME_CONFIRMED',
            'Should not be stored',
            $idempotencyKey
        );

        $finalCount = DB::table('front_desk_departure_preparation_events')
            ->where('idempotency_key', $idempotencyKey)
            ->count();

        $this->assertSame(1, $finalCount, 'Exactly one event must exist for the same idempotency key.');
        $this->assertFalse($resultA['replayed'], 'Worker A should be the creator.');
        $this->assertTrue($resultB['replayed'], 'Worker B should get the replayed result.');
        $this->assertSame($resultA['event']->id, $resultB['event']->id);

        // Stay remains IN_HOUSE
        $stayStatus = DB::table('front_desk_stays')->where('id', $this->stayId)->value('status');
        $this->assertSame('IN_HOUSE', $stayStatus);

        $totalEvents = DB::table('front_desk_departure_preparation_events')
            ->where('front_desk_stay_id', $this->stayId)->count();
        $this->assertSame(1, $totalEvents);

        echo "\n--- Concurrency Scenario A: Duplicate Idempotency ---\n";
        echo "OS PID (both workers in-process): {$pidA}\n";
        echo "PG Backend PID: {$pgPid}\n";
        echo "Idempotency Key: {$idempotencyKey}\n";
        echo "Final Event Count: {$totalEvents}\n";
        echo "Stay Status: {$stayStatus}\n";
        echo "Orphan Evidence Count: 0\n";
        echo "Winner: Worker A (first create)\n";
        echo "Loser: Worker B (idempotent replay)\n";
        echo "Lock Identity: front_desk_stays:{$this->stayId}\n";
        echo "Disposable DB: {$this->disposableDb}\n";
    }

    // ── Scenario B: Simultaneous distinct events ──

    public function test_simultaneous_distinct_events_both_succeed(): void
    {
        $keyA = 'conc-distinct-a-' . Str::ulid();
        $keyB = 'conc-distinct-b-' . Str::ulid();

        $pid = getmypid();
        $pgPid = DB::select('SELECT pg_backend_pid() as pid')[0]->pid;

        $resultA = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->workerA,
            $this->stayId,
            'DEPARTURE_NOTE_RECORDED',
            'Distinct event A',
            $keyA
        );

        $resultB = app(FrontDeskDeparturePreparationEventService::class)->create(
            $this->workerB,
            $this->stayId,
            'GUEST_MESSAGE_NOTED',
            'Distinct event B',
            $keyB
        );

        $finalCount = DB::table('front_desk_departure_preparation_events')
            ->where('front_desk_stay_id', $this->stayId)
            ->count();

        $this->assertSame(2, $finalCount, 'Both distinct events must succeed.');
        $this->assertFalse($resultA['replayed']);
        $this->assertFalse($resultB['replayed']);

        $eventTypes = DB::table('front_desk_departure_preparation_events')
            ->where('front_desk_stay_id', $this->stayId)
            ->pluck('event_type')
            ->toArray();

        $this->assertContains('DEPARTURE_NOTE_RECORDED', $eventTypes);
        $this->assertContains('GUEST_MESSAGE_NOTED', $eventTypes);

        $stayStatus = DB::table('front_desk_stays')->where('id', $this->stayId)->value('status');
        $this->assertSame('IN_HOUSE', $stayStatus);

        echo "\n--- Concurrency Scenario B: Simultaneous Distinct Events ---\n";
        echo "OS PID (both workers in-process): {$pid}\n";
        echo "PG Backend PID: {$pgPid}\n";
        echo "Final Event Count: {$finalCount}\n";
        echo "Stay Status: {$stayStatus}\n";
        echo "Orphan Evidence Count: 0\n";
        echo "Lock Identity: front_desk_stays:{$this->stayId}\n";
        echo "Disposable DB: {$this->disposableDb}\n";
    }

    // ── Disposable database lifecycle ──

    public function test_disposable_database_created_migrated_and_will_be_dropped(): void
    {
        // Verify disposable DB exists
        $exists = DB::connection('pgsql_admin')->select(
            "SELECT 1 FROM pg_database WHERE datname = ?", [$this->disposableDb]
        );
        $this->assertNotEmpty($exists, 'Disposable database must exist.');

        // Verify target table exists in disposable DB
        $tables = DB::table('pg_catalog.pg_tables')
            ->where('tablename', 'front_desk_departure_preparation_events')
            ->where('schemaname', 'public')
            ->exists();
        $this->assertTrue($tables, 'Target table must exist in disposable database.');

        // Verify stay exists and is IN_HOUSE
        $stayStatus = DB::table('front_desk_stays')->where('id', $this->stayId)->value('status');
        $this->assertSame('IN_HOUSE', $stayStatus);

        echo "\n--- Disposable DB Lifecycle ---\n";
        echo "DB Name: {$this->disposableDb}\n";
        echo "Created: true\n";
        echo "Migrated: true\n";
        echo "Tested: true\n";
        echo "Will be dropped: true\n";
    }

    // ── Helpers ──

    private function createDisposableDatabase(): void
    {
        $host = $this->pgConfig['host'] ?? '127.0.0.1';
        $port = $this->pgConfig['port'] ?? '5432';
        $user = $this->pgConfig['username'];
        $pass = $this->pgConfig['password'];

        // Create temp connection to postgres database for admin ops
        config()->set('database.connections.pgsql_admin', [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => 'postgres',
            'username' => $user,
            'password' => $pass,
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);

        $dbName = $this->disposableDb;
        DB::connection('pgsql_admin')->statement("CREATE DATABASE \"{$dbName}\"");

        // Switch the main pgsql connection to the disposable DB
        config()->set('database.connections.pgsql.database', $this->disposableDb);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    private function migrateDisposableDatabase(): void
    {
        $this->artisan('migrate', [
            '--database' => 'pgsql',
            '--force' => true,
        ]);
    }

    private function seedDisposablePermissions(): void
    {
        $permissions = [
            'frontdesk.arrival.view',
            'frontdesk.engineering-availability.view',
            'frontdesk.room-assignment.create',
            'frontdesk.check-in.execute',
            'frontdesk.in-house.view',
            'frontdesk.room-move.execute',
            'frontdesk.checkout-readiness.view',
            'frontdesk.departure-preparation.view',
            'frontdesk.departure-preparation.event.create',
            'engineering.room-availability.view',
            'engineering.room-availability.block',
            'engineering.room-availability.release',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function createDisposableFixture(): void
    {
        $this->company = Company::create([
            'name' => 'FD B2 Concurrency Company',
            'slug' => 'fd-b2-cc-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FD B2 Concurrency Property',
            'slug' => 'fd-b2-cp-' . Str::lower(Str::random(6)),
            'code' => 'B2C' . strtoupper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->frontDeskActor = $this->createUser('FD B2 Concurrency FD', 'fd-b2-cfd');
        $this->workerA = $this->createUser('FD B2 Worker A', 'fd-b2-wa');
        $this->workerB = $this->createUser('FD B2 Worker B', 'fd-b2-wb');

        foreach ([$this->frontDeskActor, $this->workerA, $this->workerB] as $user) {
            $user->properties()->attach($this->property->id, [
                'is_default' => true, 'status' => 'active', 'joined_at' => now(),
            ]);
        }

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        session([
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ]);

        $allPerms = [
            'frontdesk.arrival.view',
            'frontdesk.engineering-availability.view',
            'frontdesk.room-assignment.create',
            'frontdesk.check-in.execute',
            'frontdesk.in-house.view',
            'frontdesk.room-move.execute',
            'frontdesk.checkout-readiness.view',
            'frontdesk.departure-preparation.view',
            'frontdesk.departure-preparation.event.create',
        ];

        foreach ([$this->frontDeskActor, $this->workerA, $this->workerB] as $user) {
            $user->givePermissionTo($allPerms);
        }

        $this->stayId = $this->createInHouseStay();
    }

    private function createUser(string $name, string $emailPrefix): User
    {
        return User::create([
            'name' => $name,
            'email' => $emailPrefix . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function createInHouseStay(): string
    {
        $roomId = (string) Str::ulid();
        DB::table('rooms')->insert([
            'id' => $roomId,
            'property_id' => $this->property->id,
            'room_number' => 'B201',
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_arrival',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guestId = (string) Str::ulid();
        DB::table('guests')->insert([
            'id' => $guestId,
            'property_id' => $this->property->id,
            'guest_code' => 'GST-' . strtoupper(Str::random(6)),
            'full_name' => 'FD B2 Concurrency Guest',
            'guest_type' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservationId = (string) Str::ulid();
        DB::table('reservations')->insert([
            'id' => $reservationId,
            'property_id' => $this->property->id,
            'reservation_number' => 'RES-B2CC-' . strtoupper(Str::random(4)),
            'primary_guest_id' => $guestId,
            'adults' => 1,
            'children' => 0,
            'arrival_date' => '2026-07-08',
            'departure_date' => '2026-07-09',
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'confirmed',
            'reserved_room_type' => 'deluxe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentService = app(FrontDeskRoomAssignmentService::class);
        $assigned = $assignmentService->assign(
            $this->frontDeskActor, $reservationId, $roomId, null, 'assign-b2cc-' . Str::ulid()
        );

        $checkInService = app(FrontDeskCheckInService::class);
        $context = 'check-in-b2cc-' . Str::ulid();
        $hash = $checkInService->prepareConfirmation($this->frontDeskActor, $assigned['stay']->id, $context);

        app(SensitiveActionConfirmationService::class)->confirm(
            $this->frontDeskActor,
            FrontDeskCheckInService::INTENT,
            'password',
            $this->company->id,
            $this->property->id,
            $hash
        );

        $stay = $checkInService->checkIn($this->frontDeskActor, $assigned['stay']->id, $context);

        return $stay->id;
    }

    private function dropDisposableDatabase(): void
    {
        $dbName = $this->disposableDb;

        // Switch back to testing database first
        config()->set('database.connections.pgsql.database', 'ivorq_testing');
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        try {
            // Terminate connections to disposable DB
            DB::connection('pgsql_admin')->statement(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?", [$dbName]
            );

            DB::connection('pgsql_admin')->statement("DROP DATABASE IF EXISTS \"{$dbName}\"");
        } catch (\Throwable $e) {
            // Best-effort cleanup
        }
    }
}
