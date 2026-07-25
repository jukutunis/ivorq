<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\Postgres\Operations\FrontDesk\Support\FdC2ConcurrencyCoordinator;
use Tests\PostgresTestCase;

/**
 * FD-C2 isolated concurrency proof.
 *
 * Creates a disposable PostgreSQL database, builds the minimum FD-C2 fixture
 * chain, spawns Worker A (lock holder) and Worker B (delivery) against that
 * disposable database, proves blocking via pg_blocking_pids(), and cleans up
 * the disposable database and all temporary files through explicit finally blocks.
 */
class FrontDeskCheckoutHousekeepingHandoffIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use ManagesConcurrencyDatabase;

    private Company $company;
    private Property $property;
    private User $actor;
    private FrontDeskCheckoutHousekeepingHandoffDeliveryService $deliveryService;
    private CurrentPropertyService $currentProperty;

    private bool $concurrencyDatabaseCleaned = false;

    // ── Lifecycle ───────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConcurrencyDatabase('ivorq_concurrency_fd_c2_', '2026-07-24 10:00:00');

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        if (! $this->concurrencyDatabaseCleaned) {
            $this->tearDownConcurrencyDatabase();
        }
        parent::tearDown();
    }

    /**
     * Call tearDownConcurrencyDatabase() exactly once and mark cleanup
     * complete so tearDown() does not call it a second time.
     */
    private function cleanUpConcurrencyDatabaseOnce(): void
    {
        if (! $this->concurrencyDatabaseCleaned) {
            $this->tearDownConcurrencyDatabase();
            $this->concurrencyDatabaseCleaned = true;
        }
    }

    // ── Fixture seeding (minimum FD-C2 chain in disposable DB) ──────────

    private function seedFixtures(): void
    {
        // Switch default connection so that model events (audit logs, etc.)
        // write to the disposable database instead of ivorq_testing.
        $previousDefault = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);

        try {
            $this->seedFixturesInternal();
        } finally {
            DB::setDefaultConnection($previousDefault);
            config(['database.default' => $previousDefault]);
        }
    }

    private function seedFixturesInternal(): void
    {
        $this->company = Company::create([
            'name'      => 'FD-C2 Iso Company',
            'slug'      => 'fd-c2-iso-co-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = Property::on('pgsql_concurrency')->create([
            'company_id' => $this->company->id,
            'name'       => 'FD-C2 Iso Property',
            'slug'       => 'fd-c2-iso-prop-' . Str::lower(Str::random(6)),
            'code'       => 'FC2I' . Str::upper(Str::random(2)),
            'timezone'   => 'UTC',
            'currency'   => 'USD',
            'is_active'  => true,
        ]);

        $this->actor = User::on('pgsql_concurrency')->create([
            'name'      => 'FD-C2 Iso Actor',
            'email'     => 'fd-c2-iso-actor-' . Str::lower(Str::random(6)) . '@example.test',
            'password'  => bcrypt('password'),
            'is_active' => true,
        ]);

        $guest = Guest::on('pgsql_concurrency')->create([
            'property_id' => $this->property->id,
            'guest_code'  => 'G-ISO-' . Str::upper(Str::random(6)),
            'full_name'   => 'FD-C2 Iso Guest ' . Str::random(4),
            'guest_type'  => 'individual',
        ]);

        $reservation = Reservation::on('pgsql_concurrency')->create([
            'property_id'        => $this->property->id,
            'primary_guest_id'   => $guest->id,
            'reservation_number' => 'FD-C2-ISO-R-' . Str::upper(Str::random(6)),
            'arrival_date'       => Carbon::now()->toDateString(),
            'departure_date'     => Carbon::now()->addDays(2)->toDateString(),
            'nights'             => 2,
            'reservation_source' => 'direct',
            'status'             => 'checked_in',
            'reserved_room_type' => 'standard',
        ]);

        $stay = FrontDeskStay::on('pgsql_concurrency')->create([
            'property_id'    => $this->property->id,
            'reservation_id' => $reservation->id,
            'guest_id'       => $reservation->primary_guest_id,
            'status'         => FrontDeskStayStatusEnum::InHouse->value,
            'created_by'     => $this->actor->id,
            'updated_by'     => $this->actor->id,
        ]);

        $review = FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->create([
            'property_id'         => $this->property->id,
            'front_desk_stay_id'  => $stay->id,
            'reservation_id'      => $stay->reservation_id,
            'guest_id'            => $stay->guest_id,
            'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
            'occurred_at'         => Carbon::now(),
            'created_by'          => $this->actor->id,
            'idempotency_key'     => 'dcfr-fdc2-iso-' . Str::ulid(),
            'source_hash'         => hash('sha256', Str::ulid()->toString()),
        ]);

        $bd = PropertyBusinessDate::on('pgsql_concurrency')->create([
            'property_id'       => $this->property->id,
            'business_date'     => Carbon::now()->toDateString(),
            'status'            => PropertyBusinessDateStatusEnum::Open,
            'is_open'           => true,
            'timezone_snapshot' => 'Asia/Makassar',
            'opened_by'         => $this->actor->id,
            'opened_at'         => Carbon::now(),
        ]);

        $idemKey = 'exec-iso-' . Str::ulid();
        $execution = new FrontDeskCheckoutExecution();
        $execution->setConnection('pgsql_concurrency');
        $execution->forceFill([
            'property_id'                     => $stay->property_id,
            'front_desk_stay_id'              => $stay->id,
            'reservation_id'                  => $stay->reservation_id,
            'idempotency_key'                 => $idemKey,
            'terminal_stay_status'            => FrontDeskStayStatusEnum::CheckedOut,
            'front_desk_final_review_id'       => $review->id,
            'property_business_date_id'       => $bd->id,
            'business_date'                   => $bd->business_date,
            'night_audit_source_status'       => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint'  => hash('sha256', 'na-iso-' . $stay->id),
            'pms_financial_attestation_status'    => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => hash('sha256', 'pms-iso-' . $stay->id),
            'general_cashier_attestation_status'   => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => hash('sha256', 'gc-iso-' . $stay->id),
            'source_hash'    => hash('sha256', $stay->id . $idemKey),
            'occurred_at'    => Carbon::now()->toDateTimeString(),
            'created_by'     => $this->actor->id,
            'created_at'     => Carbon::now()->toDateTimeString(),
        ])->save();

        $this->handoffId = Str::ulid()->toString();
        $this->handoffCorrelationKey = 'corr-lockwait-iso-' . Str::ulid();
        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $handoff->setConnection('pgsql_concurrency');
        $handoff->forceFill([
            'id'                        => $this->handoffId,
            'property_id'               => $execution->property_id,
            'front_desk_stay_id'        => $execution->front_desk_stay_id,
            'reservation_id'            => $execution->reservation_id,
            'checkout_execution_id'     => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date'             => $execution->business_date,
            'idempotency_key'           => 'idem-lockwait-iso-' . Str::ulid(),
            'correlation_key'           => $this->handoffCorrelationKey,
            'source_hash'               => hash('sha256', $execution->id . 'iso'),
            'delivery_status'           => FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending->value,
            'attempts'                  => 0,
            'available_at'              => Carbon::now()->subDay()->toDateTimeString(),
            'occurred_at'               => Carbon::now()->toDateTimeString(),
            'created_at'                => Carbon::now()->toDateTimeString(),
            'updated_at'                => Carbon::now()->toDateTimeString(),
        ])->save();

        // Resolve services for the main test process.
        // Set the property ID on the shared CurrentPropertyService singleton
        // so that the delivery service can resolve it.
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->currentProperty = app(CurrentPropertyService::class);
    }

    // ── Property bag ────────────────────────────────────────────────────

    private string $handoffId;
    private string $handoffCorrelationKey;

    // ── Concurrency proof ───────────────────────────────────────────────

    public function test_lock_wait_expiry_isolated_concurrency_proof(): void
    {
        $disposableDb = $this->concurrencyDb;

        // Set the handoff to CLAIMED with a 15-second lease directly via
        // raw SQL on the disposable DB. A longer lease ensures Worker B
        // has ample time to bootstrap and block before expiry.
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        DB::connection('pgsql_concurrency')
            ->table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $this->handoffId)
            ->update([
                'delivery_status'   => 'CLAIMED',
                'attempts'          => 1,
                'claimed_at'        => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at'  => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '15 seconds'"),
                'claim_token_hash'  => $tokenHash,
                'updated_at'        => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
            ]);

        $token = $rawToken;
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));

        $coordinator = null;
        $lockAcquiredMarker = null;
        $holdUntilPath = null;
        $workerBPreLockMarker = null;
        $lockEvidence = null;
        $workerBPreLockEvidence = null;

        try {
            $coordinator = new FdC2ConcurrencyCoordinator();

            $lockAcquiredMarker = tempnam(sys_get_temp_dir(), 'fdc2_la_');
            $holdUntilPath = tempnam(sys_get_temp_dir(), 'fdc2_hu_');
            @unlink($holdUntilPath);
            $workerBPreLockMarker = tempnam(sys_get_temp_dir(), 'fdc2_plb_');

            $coordinator->trackMarker($lockAcquiredMarker);
            $coordinator->trackMarker($holdUntilPath);
            $coordinator->trackMarker($workerBPreLockMarker);

            $workerEnv = [
                'APP_ENV'      => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE'  => $disposableDb,
            ];

            // ── Worker A: lock holder ───────────────────────────────────
            $workerA = $coordinator->spawnWorker('lock_hold', [
                'handoff_id'           => $this->handoffId,
                'lock_acquired_marker'  => $lockAcquiredMarker,
                'hold_until_path'       => $holdUntilPath,
            ], $workerEnv);

            $lockEvidence = $coordinator->waitForMarker($lockAcquiredMarker, 10);
            if (isset($lockEvidence['exception_class'])) {
                $this->fail("Worker A crashed: {$lockEvidence['exception_class']} — {$lockEvidence['database_message']}");
            }
            $this->assertNotEmpty($lockEvidence['php_pid'] ?? null, 'Worker A must report PHP PID.');
            $this->assertNotEmpty($lockEvidence['pg_backend_pid'] ?? null, 'Worker A must report PG backend PID.');
            $this->assertNotEmpty($lockEvidence['txid'] ?? null, 'Worker A must report transaction ID.');
            $workerAPgPid = $lockEvidence['pg_backend_pid'];

            // ── Worker B: deliver ───────────────────────────────────────
            $workerB = $coordinator->spawnWorker('deliver', [
                'property_id'     => $this->property->id,
                'handoff_id'      => $this->handoffId,
                'claim_token'     => $token,
                'pre_lock_marker' => $workerBPreLockMarker,
            ], $workerEnv);

            $workerBPreLockEvidence = $coordinator->waitForMarker($workerBPreLockMarker, 10);
            $this->assertNotEmpty($workerBPreLockEvidence['php_pid'] ?? null, 'Worker B must report PHP PID.');
            $this->assertNotEmpty($workerBPreLockEvidence['postgres_backend_pid'] ?? null, 'Worker B must report PG backend PID.');
            // Worker B's pre-lock txid is intentionally null — the actual
            // blocked transaction opens inside production markDelivered().
            // The authoritative transaction evidence is obtained later via
            // getBlockedTransactionEvidence() using pg_stat_activity.
            $workerBPgPid = $workerBPreLockEvidence['postgres_backend_pid'];

            // Distinct PIDs
            $this->assertNotEquals($lockEvidence['php_pid'], $workerBPreLockEvidence['php_pid'], 'Worker A and B PHP PIDs must differ.');
            $this->assertNotEquals($workerAPgPid, $workerBPgPid, 'Worker A and B PG backend PIDs must differ.');
            // Worker A lock txid (post-beginTransaction) is verified in the
            // blocking proof below via getBlockedTransactionEvidence().

            // Give Worker B time to enter markDelivered() and block
            usleep(1_000_000);

            // ── Blocking proof ──────────────────────────────────────────
            $blockingProof = $coordinator->proveBlockedBy($workerBPgPid, $workerAPgPid);
            $this->assertTrue($blockingProof, 'pg_blocking_pids must show Worker B blocked by Worker A.');

            // ── Virtual transaction identity ────────────────────────────
            $vxids = $coordinator->getVirtualTransactionIds($workerAPgPid, $workerBPgPid);
            $this->assertNotEmpty($vxids['worker_a_vxid'] ?? null, 'Worker A must have a virtual transaction.');
            $this->assertNotEmpty($vxids['worker_b_vxid'] ?? null, 'Worker B must have a virtual transaction.');
            $this->assertNotEquals(
                $vxids['worker_a_vxid'],
                $vxids['worker_b_vxid'],
                'Worker A and B virtual transaction IDs must differ.'
            );
            $blockedTxEvidence = $coordinator->getBlockedTransactionEvidence($workerBPgPid);
            $this->assertNotNull($blockedTxEvidence, 'Worker B transaction evidence must exist.');
            // backend_xid is set when the backend has performed writes; on some
            // PG versions a read-only FOR UPDATE lock wait may not populate it.
            // xact_start proves the transaction is active regardless.
            $this->assertNotNull($blockedTxEvidence['xact_start'], 'Worker B must have an active transaction (xact_start).');
            $this->assertSame('active', $blockedTxEvidence['state'], 'Worker B must be in active state.');
            $this->assertSame('Lock', $blockedTxEvidence['wait_event_type'], 'Worker B must be waiting for a lock.');
            // Worker A lock txid vs Worker B blocked txid — if backend_xid is
            // available, prove they differ; otherwise this is proven by the
            // distinct PG backend PIDs already established above.
            if ($blockedTxEvidence['backend_xid'] !== null) {
                $this->assertNotEquals(
                    $lockEvidence['txid'],
                    $blockedTxEvidence['backend_xid'],
                    'Worker A lock transaction must differ from Worker B blocked transaction.'
                );
            }

            // Lease active while blocking is proven — query remaining duration
            $leaseRemaining = DB::connection('pgsql_concurrency')->selectOne(
                "SELECT EXTRACT(EPOCH FROM (claim_expires_at - (clock_timestamp() AT TIME ZONE 'UTC'))) AS remaining_seconds
                   FROM front_desk_checkout_housekeeping_handoffs WHERE id = ?",
                [$this->handoffId]
            );
            $this->assertNotNull($leaseRemaining, 'Must be able to query remaining lease.');
            $this->assertGreaterThan(0, (float) $leaseRemaining->remaining_seconds,
                'Lease must still be comfortably active when Worker B is blocked.');

            // Poll for lease expiry using a finite deadline (not fixed sleep)
            $deadline = microtime(true) + 30;
            $leaseExpired = null;
            do {
                $leaseExpired = DB::connection('pgsql_concurrency')->selectOne(
                    "SELECT 1 FROM front_desk_checkout_housekeeping_handoffs WHERE id = ? AND claim_expires_at <= (clock_timestamp() AT TIME ZONE 'UTC')",
                    [$this->handoffId]
                );
                if ($leaseExpired !== null) {
                    break;
                }
                usleep(100_000);
            } while (microtime(true) < $deadline);

            $this->assertNotNull($leaseExpired, 'Lease must be expired before releasing Worker A.');

            // ── Release Worker A ────────────────────────────────────────
            $coordinator->releaseWorker($holdUntilPath);
            $workerAResult = $coordinator->waitForWorker($workerA, 15);

            // ── Worker B rejection ──────────────────────────────────────
            $workerBResult = $coordinator->waitForRejectedWorker($workerB, 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM', 15);
            $this->assertNotEmpty($workerBResult['php_pid'] ?? null, 'Worker B must report PHP PID.');
            $this->assertNotEmpty($workerBResult['postgres_backend_pid'] ?? null, 'Worker B must report PG backend PID.');

            // ── Prove worker termination (successful-path evidence) ─────
            $this->assertTrue(
                $workerAResult['coordinator_process_terminated'] ?? false,
                'Worker A must be confirmed terminated by the coordinator.'
            );
            $this->assertTrue(
                $workerBResult['coordinator_process_terminated'] ?? false,
                'Worker B must be confirmed terminated by the coordinator.'
            );

            // ── Final state ─────────────────────────────────────────────
            $row = DB::connection('pgsql_concurrency')
                ->table('front_desk_checkout_housekeeping_handoffs')
                ->where('id', $this->handoffId)
                ->first();
            $this->assertSame('CLAIMED', $row->delivery_status);
            $this->assertNull($row->delivered_at);
            $this->assertNull($row->failed_at);
            // attempts and token hash unchanged from the claim
            $this->assertSame(1, (int) $row->attempts);
            $this->assertSame($tokenHash, $row->claim_token_hash);

        } finally {
            // ── Explicit cleanup and zero-residue proof ─────────────────

            // Terminate all workers and collect cleanup report
            $cleanupReport = [];
            if ($coordinator !== null) {
                $cleanupReport = $coordinator->terminateAllWorkers(500);
            }

            // Assert cleanup report: each worker's handle closed and payload deleted
            foreach ($cleanupReport as $entry) {
                $this->assertTrue($entry['process_handle_closed'] ?? false,
                    "Worker {$entry['mode']} process handle must be closed.");
                $this->assertTrue($entry['payload_file_deleted'] ?? false,
                    "Worker {$entry['mode']} payload file must be deleted.");
                // Verify the exact payload file from this coordinator run
                $payloadPath = $entry['payload_file_path'] ?? null;
                if ($payloadPath !== null) {
                    $this->assertFileDoesNotExist($payloadPath,
                        "Payload file for {$entry['mode']} must not exist: {$payloadPath}");
                }
            }

            // Clean up marker files
            foreach ([$lockAcquiredMarker, $holdUntilPath, $workerBPreLockMarker] as $path) {
                if ($path !== null) {
                    @unlink($path);
                    clearstatcache(true, $path);
                }
            }

            // Tear down the disposable database exactly once.
            // This also calls Carbon::setTestNow() to reset the frozen clock.
            $dbName = $this->concurrencyDb ?? '';
            $this->cleanUpConcurrencyDatabaseOnce();

            // ── Prove database is gone ──────────────────────────────────
            if ($dbName !== '') {
                $dbCheck = DB::selectOne(
                    'SELECT COUNT(*) AS database_count FROM pg_database WHERE datname = ?',
                    [$dbName]
                );
                $this->assertSame(0, (int) $dbCheck->database_count,
                    "Disposable database '{$dbName}' must not exist after cleanup."
                );
            }

            // ── Prove Carbon clock is reset ─────────────────────────────
            $this->assertFalse(
                \Illuminate\Support\Carbon::hasTestNow(),
                'Carbon test-now must be cleared after isolated concurrency test.'
            );

            // ── Prove marker files do not exist ─────────────────────────
            foreach ([$lockAcquiredMarker, $holdUntilPath, $workerBPreLockMarker] as $path) {
                if ($path !== null) {
                    for ($attempt = 0; $attempt < 3 && file_exists($path); $attempt++) {
                        @unlink($path);
                        clearstatcache(true, $path);
                        if (file_exists($path)) {
                            usleep(100_000);
                        }
                    }
                    $this->assertFileDoesNotExist($path, "Marker file must not exist: {$path}");
                }
            }

            // ── Prove payload JSON files from this run are gone ─────────
            // Verified above via cleanupReport payload_file_path assertions.
        }
    }

}

