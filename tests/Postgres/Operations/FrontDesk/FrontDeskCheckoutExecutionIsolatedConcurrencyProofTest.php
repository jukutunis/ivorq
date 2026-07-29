<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Modules\Operations\NightAudit\Services\NightAuditRunStartService;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\Postgres\Operations\FrontDesk\Support\P9CheckoutExecutionConcurrencyCoordinator;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use ManagesConcurrencyDatabase;

    private const WORKER_MARKER_TIMEOUT_SECONDS = 60;
    private const WORKER_RESULT_TIMEOUT_SECONDS = 60;
    private const BLOCKING_PROOF_TIMEOUT_SECONDS = 30;

    private Company $company;
    private Property $property;
    private User $actor;
    private string $markerDir;
    private bool $concurrencyDbCleaned = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrencyDatabase('ivorq_concurrency_p9_', '2026-07-28 10:00:00');
        $this->markerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p9_markers_' . Str::random(8);
        mkdir($this->markerDir, 0700, true);
        $this->bindClearPmsParticipationPorts();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        if (!$this->concurrencyDbCleaned) {
            $this->tearDownConcurrencyDatabase();
        }
        $this->rmdirRecursive($this->markerDir);
        parent::tearDown();
    }

    private function cleanUpConcurrencyDbOnce(): void
    {
        if (!$this->concurrencyDbCleaned) {
            $this->tearDownConcurrencyDatabase();
            $this->concurrencyDbCleaned = true;
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function seedFixtures(): void
    {
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        try {
            $this->company = Company::create(['name' => 'P9 Iso Co', 'slug' => 'p9-iso-co-' . Str::lower(Str::random(6)), 'is_active' => true]);
            $this->property = Property::on('pgsql_concurrency')->create(['company_id' => $this->company->id, 'name' => 'P9 Iso Prop', 'slug' => 'p9-iso-prop-' . Str::lower(Str::random(6)), 'code' => 'P9I' . Str::upper(Str::random(2)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
            $this->actor = User::on('pgsql_concurrency')->create(['name' => 'P9 Iso Actor', 'email' => 'p9-iso-' . Str::lower(Str::random(6)) . '@test', 'password' => bcrypt('password'), 'is_active' => true]);
            $this->actor->properties()->attach($this->property->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
            Permission::firstOrCreate(['name' => \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => PropertyBusinessDateAuthorizationService::VIEW_PERMISSION, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => NightAuditAuthorizationService::VIEW_PERMISSION, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => NightAuditAuthorizationService::START_PERMISSION, 'guard_name' => 'web']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->actor->givePermissionTo([
                \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION,
                PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
                NightAuditAuthorizationService::VIEW_PERMISSION,
                NightAuditAuthorizationService::START_PERMISSION,
            ]);
            app(CurrentPropertyService::class)->setPropertyId($this->property->id);
            // Create one business date per property (unique constraint)
            PropertyBusinessDate::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'business_date' => Carbon::now()->toDateString(), 'status' => PropertyBusinessDateStatusEnum::Open, 'is_open' => true, 'timezone_snapshot' => 'UTC', 'opened_by' => $this->actor->id, 'opened_at' => Carbon::now()]);
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    private function bindClearPmsParticipationPorts(): void
    {
        $clearResult = static fn (string $fingerprint, string $reservationId, string $propertyId): array => [
            'status' => 'AVAILABLE_CLEAR',
            'code' => null,
            'source_fingerprint' => hash('sha256', $fingerprint . '|' . $propertyId . '|' . $reservationId),
            'source_identifiers' => [],
        ];

        app()->singleton(GuestLedgerPostingCompletenessParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerPostingCompletenessParticipationPort {
            public function __construct(private readonly \Closure $clearResult) {}
            public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-posting-completeness', $reservationId, $propertyId); }
        });
        app()->singleton(GuestLedgerSettlementHoldParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerSettlementHoldParticipationPort {
            public function __construct(private readonly \Closure $clearResult) {}
            public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-settlement-hold', $reservationId, $propertyId); }
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function __construct(private readonly \Closure $clearResult) {}
            public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-completed-settlement', $reservationId, $propertyId); }
        });
    }

    private function actingAsConcurrencyActor(array $fixture): void
    {
        $this->actingAs($this->actor, 'web');
        session([
            'active_property_id'  => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id'   => $this->property->company_id,
            CheckoutSensitiveConfirmationService::SESSION_KEY => [
                CheckoutSensitiveConfirmationService::INTENT => [
                    'actor_id'                 => $this->actor->id,
                    'intent'                   => CheckoutSensitiveConfirmationService::INTENT,
                    'company_id'               => $this->property->company_id,
                    'property_id'              => $this->property->id,
                    'front_desk_stay_id'       => $fixture['front_desk_stay_id'],
                    'checkout_idempotency_key' => $fixture['checkout_idempotency_key'],
                    'issuance_id'              => $fixture['issuance_id'],
                    'confirmation_identity'    => $fixture['confirmation_identity'],
                    'confirmation_fingerprint' => $fixture['confirmation_fingerprint'],
                    'session_fingerprint'      => $fixture['session_fingerprint'],
                    'confirmed_at'             => $fixture['confirmed_at'],
                    'expires_at'               => $fixture['expires_at'],
                ],
            ],
        ]);
    }

    private function withConcurrencyConnection(callable $callback): mixed
    {
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    private function createCheckoutFixture(string $roomNum, string $idempotencyKey, ?string $existingStayId = null, ?Property $property = null, int $ttlSeconds = 900): array
    {
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        try {
            $fixtureProperty = $property ?? $this->property;
            if ($existingStayId !== null) {
                $stay = FrontDeskStay::on('pgsql_concurrency')->findOrFail($existingStayId);
                $res = Reservation::on('pgsql_concurrency')->findOrFail($stay->reservation_id);
                $fixtureProperty = Property::on('pgsql_concurrency')->findOrFail($stay->property_id);
            } else {
                $guest = Guest::on('pgsql_concurrency')->create(['property_id' => $fixtureProperty->id, 'guest_code' => 'G-' . Str::upper(Str::random(6)), 'full_name' => 'Guest ' . Str::random(4), 'guest_type' => 'individual']);
                $res = Reservation::on('pgsql_concurrency')->create(['property_id' => $fixtureProperty->id, 'primary_guest_id' => $guest->id, 'reservation_number' => 'R-' . Str::upper(Str::random(6)), 'arrival_date' => Carbon::now()->toDateString(), 'departure_date' => Carbon::now()->addDays(2)->toDateString(), 'nights' => 2, 'reservation_source' => 'direct', 'status' => 'checked_in', 'reserved_room_type' => 'standard']);
                $stay = FrontDeskStay::on('pgsql_concurrency')->create(['property_id' => $fixtureProperty->id, 'reservation_id' => $res->id, 'guest_id' => $res->primary_guest_id, 'status' => FrontDeskStayStatusEnum::InHouse->value, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
                $folio = new Folio();
                $folio->setConnection('pgsql_concurrency');
                $folio->forceFill([
                    'property_id' => $fixtureProperty->id,
                    'folio_number' => 'P9-' . Str::upper(Str::random(8)),
                    'reservation_id' => $res->id,
                    'guest_id' => $res->primary_guest_id,
                    'status' => FolioStatusEnum::Open->value,
                    'currency' => $fixtureProperty->currency ?? 'USD',
                    'window_number' => 1,
                    'opening_idempotency_key' => 'p9-folio-' . Str::ulid(),
                    'total_charges' => '0.00',
                    'total_payments' => '0.00',
                    'total_deposits' => '0.00',
                    'total_ar_transfers' => '0.00',
                    'balance' => '0.00',
                    'created_by' => $this->actor->id,
                    'updated_by' => $this->actor->id,
                ])->save();
                $occ = Carbon::now();
                FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->create(['property_id' => $fixtureProperty->id, 'front_desk_stay_id' => $stay->id, 'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id, 'final_review_status' => FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady->value, 'idempotency_key' => 'review-' . Str::ulid(), 'source_hash' => hash('sha256', implode('|', [$stay->id, 'CHECKOUT_FINAL_REVIEW_READY', '', $occ->toISOString()])), 'occurred_at' => $occ, 'created_by' => $this->actor->id, 'created_at' => $occ]);
            }
            $issId = (string) Str::ulid(); $ident = (string) Str::ulid();
            $sessId = session()->getId(); $sessFp = CheckoutSensitiveConfirmationService::fingerprintSession($sessId);
            $dbNow = Carbon::parse(DB::connection('pgsql_concurrency')->selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc")->wall_clock_utc);
            $confAt = $dbNow; $expAt = $dbNow->copy()->addSeconds($ttlSeconds);
            $fp = hash('sha256', implode('|', [CheckoutSensitiveConfirmationService::INTENT, $ident, $this->actor->id, $fixtureProperty->company_id, $fixtureProperty->id, $stay->id, $idempotencyKey, $sessFp, $confAt->toISOString(), $expAt->toISOString()]));
            DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_issuances')->insert(['id' => $issId, 'confirmation_identity' => $ident, 'intent' => CheckoutSensitiveConfirmationService::INTENT, 'actor_id' => $this->actor->id, 'company_id' => $fixtureProperty->company_id, 'property_id' => $fixtureProperty->id, 'front_desk_stay_id' => $stay->id, 'checkout_idempotency_key' => $idempotencyKey, 'session_fingerprint' => $sessFp, 'confirmation_fingerprint' => $fp, 'confirmed_at' => $confAt, 'expires_at' => $expAt, 'created_at' => $confAt]);
            return ['property_id' => $fixtureProperty->id, 'company_id' => $fixtureProperty->company_id, 'actor_id' => $this->actor->id, 'front_desk_stay_id' => $stay->id, 'reservation_id' => $res->id, 'checkout_idempotency_key' => $idempotencyKey, 'issuance_id' => $issId, 'confirmation_identity' => $ident, 'confirmation_fingerprint' => $fp, 'session_fingerprint' => $sessFp, 'session_id' => $sessId, 'confirmed_at' => $confAt->toISOString(), 'expires_at' => $expAt->toISOString(), 'marker_dir' => $this->markerDir, 'database' => $this->concurrencyDb, 'stay' => $stay];
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    /**
     * @param array{mode: string, exit_code: int, stdout: string, stderr: string, payload: array<string, mixed>} $worker
     * @return array<string, mixed>
     */
    private function assertCleanWorkerResult(array $worker, string $expectedResult, ?string $expectedMessage = null): array
    {
        $payload = $worker['payload'];
        $evidence = json_encode($worker, JSON_UNESCAPED_SLASHES);

        $this->assertSame(0, $worker['exit_code'], $evidence);
        $this->assertSame('', trim($worker['stderr']), $evidence);
        $this->assertNotSame('worker_error', $payload['result'] ?? null, $evidence);
        $this->assertSame($expectedResult, $payload['result'] ?? null, $evidence);
        $this->assertGreaterThan(0, (int) ($payload['backend_pid'] ?? 0), $evidence);
        $this->assertNotEmpty($payload['property_id'] ?? null, $evidence);
        $this->assertNotEmpty($payload['front_desk_stay_id'] ?? null, $evidence);

        if ($expectedMessage !== null) {
            $this->assertSame($expectedMessage, $payload['message'] ?? null, $evidence);
        }

        return $payload;
    }

    /**
     * @return array{proc: resource, result: string, barrier: string, run_id: string}
     */
    private function spawnNightAuditWorker(string $mode, string $workerId): array
    {
        $runId = (string) Str::ulid();
        $barrier = $this->markerDir . DIRECTORY_SEPARATOR . 'na-barrier-' . Str::lower(Str::random(6));
        $resultFile = $this->markerDir . DIRECTORY_SEPARATOR . "na-result-{$workerId}.json";
        $stderrFile = $this->markerDir . DIRECTORY_SEPARATOR . "na-stderr-{$workerId}.txt";
        $argsFile = $this->markerDir . DIRECTORY_SEPARATOR . "na-args-{$workerId}.json";

        file_put_contents($argsFile, json_encode([
            'worker_id' => $workerId,
            'mode' => $mode,
            'run_id' => $runId,
            'result_file' => $resultFile,
            'barrier' => $barrier,
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'property_business_date_id' => $this->businessDateEvidence()['property_business_date_id'],
            'actor_id' => $this->actor->id,
            'business_date_evidence' => $this->businessDateEvidence(),
            'test_now' => '2026-07-28T10:00:00Z',
        ], JSON_UNESCAPED_SLASHES));

        $worker = base_path('tests/Postgres/Operations/NightAudit/Support/NightAuditCheckoutConcurrencyWorker.php');
        $cmd = sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($worker), escapeshellarg($argsFile));
        $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
        $proc = proc_open($cmd, $spec, $pipes, base_path(), array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $this->concurrencyDb,
        ]));

        if (! is_resource($proc)) {
            $this->fail('Unable to spawn real NA-A2 worker.');
        }

        fclose($pipes[0]);

        return ['proc' => $proc, 'result' => $resultFile, 'barrier' => $barrier, 'run_id' => $runId];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessDateEvidence(): array
    {
        $row = DB::connection('pgsql_concurrency')->table('property_business_dates')
            ->where('property_id', $this->property->id)
            ->where('is_open', true)
            ->first();

        $this->assertNotNull($row, 'Fixture property must have an open business date.');

        return [
            'property_id' => (string) $row->property_id,
            'property_business_date_id' => (string) $row->id,
            'business_date' => Carbon::parse($row->business_date)->toDateString(),
            'property_timezone' => (string) $row->timezone_snapshot,
            'opened_by' => (string) $row->opened_by,
            'opened_at' => Carbon::parse($row->opened_at)->utc()->toISOString(),
        ];
    }

    private function waitForNightAuditBarrier(string $barrier, string $name, string $runId, int $timeoutMs = 10000): void
    {
        $path = $barrier . '-' . $name . '.json';
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $payload = json_decode((string) file_get_contents($path), true);
                if (is_array($payload) && ($payload['run_id'] ?? null) === $runId) {
                    return;
                }
            }
            usleep(25000);
        }

        $this->fail("Timed out waiting for NA-A2 barrier {$name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function readNightAuditResult(string $path): array
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $payload = json_decode((string) file_get_contents($path), true);
                $this->assertIsArray($payload, "Missing or malformed NA-A2 result {$path}");

                return $payload;
            }
            usleep(25000);
        }

        $this->fail("Timed out reading NA-A2 result {$path}.");
    }

    private function waitProcess($proc, int $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($proc);
            if (! ($status['running'] ?? false)) {
                $exit = (int) ($status['exitcode'] ?? -1);
                proc_close($proc);

                return $exit;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($proc);
        proc_close($proc);

        return 124;
    }

    private function assertRealTerminalStatuses(string $stayId): void
    {
        $row = DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')
            ->where('front_desk_stay_id', $stayId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR, (string) $row->night_audit_source_status);
        $this->assertSame(GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady->value, (string) $row->pms_financial_attestation_status);
        $this->assertSame(GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear->value, (string) $row->general_cashier_attestation_status);
        $this->assertNotEmpty((string) $row->night_audit_source_fingerprint);
        $this->assertNotEmpty((string) $row->pms_financial_attestation_fingerprint);
        $this->assertNotEmpty((string) $row->general_cashier_attestation_fingerprint);
    }

    private function assertExactRuntimeCounts(int $expected): void
    {
        $this->assertSame($expected, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
        $this->assertSame($expected, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
        $this->assertSame($expected, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
        $this->assertSame($expected, DB::connection('pgsql_concurrency')->table('audit_logs')->where('event', 'front_desk_checkout_completed')->count());
    }

    // ═══ Scenario A: same stay, same key ═══

    /**
     * @return array{sequence: string, function: string, trigger: string, sqlstate: string, fail_attempts: int}
     */
    private function installCheckoutInsertSqlstateFault(string $sqlState, int $failAttempts): array
    {
        $suffix = Str::lower(Str::random(10));
        $sequence = "p9_checkout_fault_seq_{$suffix}";
        $function = "p9_checkout_fault_fn_{$suffix}";
        $trigger = "p9_checkout_fault_trg_{$suffix}";

        DB::connection('pgsql_concurrency')->unprepared("
            CREATE SEQUENCE {$sequence};

            CREATE FUNCTION {$function}() RETURNS trigger AS $$
            DECLARE
                observed_attempt bigint;
            BEGIN
                observed_attempt := nextval('{$sequence}');
                IF observed_attempt <= {$failAttempts} THEN
                    RAISE EXCEPTION 'P9 runtime checkout insert fault attempt %', observed_attempt
                        USING ERRCODE = '{$sqlState}';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER {$trigger}
                AFTER INSERT ON front_desk_checkout_executions
                FOR EACH ROW
                EXECUTE FUNCTION {$function}();
        ");

        return ['sequence' => $sequence, 'function' => $function, 'trigger' => $trigger, 'sqlstate' => $sqlState, 'fail_attempts' => $failAttempts];
    }

    /**
     * @param array{sequence: string, function: string, trigger: string, sqlstate: string, fail_attempts: int} $fault
     */
    private function dropCheckoutInsertSqlstateFault(array $fault): void
    {
        DB::connection('pgsql_concurrency')->unprepared("
            DROP TRIGGER IF EXISTS {$fault['trigger']} ON front_desk_checkout_executions;
            DROP FUNCTION IF EXISTS {$fault['function']}();
            DROP SEQUENCE IF EXISTS {$fault['sequence']};
        ");
    }

    /**
     * @param array{sequence: string, function: string, trigger: string, sqlstate: string, fail_attempts: int} $fault
     */
    private function checkoutFaultAttemptCount(array $fault): int
    {
        $row = DB::connection('pgsql_concurrency')->selectOne("SELECT last_value, is_called FROM {$fault['sequence']}");

        return ($row && $row->is_called) ? (int) $row->last_value : 0;
    }

    /**
     * @param array{sequence: string, function: string, trigger: string, sqlstate: string, fail_attempts: int} $fault
     * @return list<array{attempt: int, sqlstate: string, raised: bool}>
     */
    private function checkoutFaultTelemetry(array $fault): array
    {
        $attempts = $this->checkoutFaultAttemptCount($fault);
        $telemetry = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $raised = $attempt <= $fault['fail_attempts'];
            $telemetry[] = [
                'attempt' => $attempt,
                'sqlstate' => $raised ? $fault['sqlstate'] : 'COMMITTED_INSERT',
                'raised' => $raised,
            ];
        }

        return $telemetry;
    }

    private function installScenarioIRetryRuntimeTelemetryObservers(): object
    {
        $telemetry = new class {
            public bool $active = true;

            /** @var list<array<string, mixed>> */
            public array $events = [];

            /** @var list<array<string, mixed>> */
            public array $queries = [];

            private int $attempt = 1;

            public function beginAuthorityAttempt(): void
            {
                if (! $this->active) {
                    return;
                }

                if ($this->eventCount($this->attempt, 'final_confirmation_claim_attempt') > 0) {
                    $this->attempt++;
                }
            }

            /**
             * @param array<string, mixed> $evidence
             */
            public function record(string $boundary, array $evidence = []): void
            {
                if (! $this->active) {
                    return;
                }

                $this->events[] = [
                    'attempt' => $this->attempt,
                    'boundary' => $boundary,
                    'evidence' => $evidence,
                ];
            }

            public function recordQuery(\Illuminate\Database\Events\QueryExecuted $query): void
            {
                if (! $this->active || $query->connectionName !== 'pgsql_concurrency') {
                    return;
                }

                $sql = trim(strtolower((string) preg_replace('/\s+/', ' ', $query->sql)));
                if ($sql === '') {
                    return;
                }

                if (str_contains($sql, 'front_desk_checkout_executions')
                    && str_contains($sql, 'idempotency_key')
                    && str_starts_with($sql, 'select')
                    && ! str_contains($sql, 'for update')) {
                    $this->queries[] = [
                        'attempt' => $this->attempt,
                        'category' => 'committed_replay_resolution',
                        'connection' => $query->connectionName,
                        'sql_shape_hash' => hash('sha256', $sql),
                        'binding_count' => count($query->bindings),
                    ];
                }

                if (str_contains($sql, 'txid_current()')) {
                    $this->queries[] = [
                        'attempt' => $this->attempt,
                        'category' => 'postgresql_transaction_id_query',
                        'connection' => $query->connectionName,
                        'sql_shape_hash' => hash('sha256', $sql),
                        'binding_count' => count($query->bindings),
                    ];
                }
            }

            public function eventCount(int $attempt, string $boundary): int
            {
                return count(array_filter(
                    $this->events,
                    fn (array $event): bool => (int) $event['attempt'] === $attempt
                        && $event['boundary'] === $boundary
                ));
            }

            public function queryCount(int $attempt, string $category): int
            {
                return count(array_filter(
                    $this->queries,
                    fn (array $query): bool => (int) $query['attempt'] === $attempt
                        && $query['category'] === $category
                ));
            }

            /**
             * @return list<string>
             */
            public function transactionIds(): array
            {
                $ids = [];
                foreach ($this->events as $event) {
                    if ($event['boundary'] !== 'postgresql_transaction') {
                        continue;
                    }

                    $id = trim((string) ($event['evidence']['postgres_transaction_id'] ?? ''));
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }

                return $ids;
            }

            /**
             * @return array{events: list<array<string, mixed>>, queries: list<array<string, mixed>>}
             */
            public function publicEvidence(): array
            {
                return ['events' => $this->events, 'queries' => $this->queries];
            }

            public function disable(): void
            {
                $this->active = false;
            }
        };

        $realAuthorization = new \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService(
            app(\Shared\Services\CurrentPropertyService::class)
        );
        $observedAuthorization = new class($realAuthorization, $telemetry) extends \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService {
            public function __construct(
                private readonly \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService $delegate,
                private readonly object $telemetry,
            ) {}

            public function resolveAuthorizedStay(\Modules\Foundation\User\Models\User $actor, string $frontDeskStayId): \Modules\Operations\FrontDesk\Models\FrontDeskStay
            {
                return $this->delegate->resolveAuthorizedStay($actor, $frontDeskStayId);
            }

            public function resolveAuthorizedContext(\Modules\Foundation\User\Models\User $actor, string $frontDeskStayId): array
            {
                $this->telemetry->beginAuthorityAttempt();
                $context = $this->delegate->resolveAuthorizedContext($actor, $frontDeskStayId);

                $this->telemetry->record('authorization_current_actor_authority', [
                    'actor_authorized' => $context['actor']->id === $actor->id,
                    'driver' => DB::connection()->getDriverName(),
                ]);
                $this->telemetry->record('current_company_property_resolution', [
                    'company_resolved' => is_string($context['company']->id) && $context['company']->id !== '',
                    'property_resolved' => is_string($context['property']->id) && $context['property']->id !== '',
                    'property_company_match' => $context['property']->company_id === $context['company']->id,
                ]);
                $this->telemetry->record('requested_stay_resolution', [
                    'stay_resolved' => $context['stay']->id === $frontDeskStayId,
                    'stay_property_match' => $context['stay']->property_id === $context['property']->id,
                ]);

                return $context;
            }

            public function authorize(\Modules\Foundation\User\Models\User $actor): array
            {
                return $this->delegate->authorize($actor);
            }
        };

        $realConfirmation = new \Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService(
            app(\Modules\Foundation\Audit\Services\AuditService::class),
            $realAuthorization
        );
        $observedConfirmation = new class($realConfirmation, $telemetry) extends \Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService {
            public function __construct(
                private readonly \Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService $delegate,
                private readonly object $telemetry,
            ) {}

            public function issueForCurrentSession(\Modules\Foundation\User\Models\User $actor, string $frontDeskStayId, string $checkoutIdempotencyKey, string $password): \Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationIssuance
            {
                return $this->delegate->issueForCurrentSession($actor, $frontDeskStayId, $checkoutIdempotencyKey, $password);
            }

            public function claimCurrentSessionConfirmationFor(\Modules\Foundation\User\Models\User $actor, string $frontDeskStayId, string $checkoutIdempotencyKey): \Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationClaimResult
            {
                $result = $this->delegate->claimCurrentSessionConfirmationFor($actor, $frontDeskStayId, $checkoutIdempotencyKey);
                $this->telemetry->record('final_confirmation_claim_attempt', [
                    'claimed' => $result->frontDeskStayId === $frontDeskStayId,
                    'driver' => DB::connection()->getDriverName(),
                    'transaction_level' => DB::transactionLevel(),
                ]);

                return $result;
            }

            public function validateCurrentSessionConfirmationFor(\Modules\Foundation\User\Models\User $actor, string $frontDeskStayId, string $checkoutIdempotencyKey): \Modules\Foundation\Authorization\ValueObjects\CheckoutSensitiveConfirmationPreflightResult
            {
                $result = $this->delegate->validateCurrentSessionConfirmationFor($actor, $frontDeskStayId, $checkoutIdempotencyKey);
                $this->telemetry->record('confirmation_preflight', [
                    'validated' => $result->frontDeskStayId === $frontDeskStayId,
                    'driver' => DB::connection()->getDriverName(),
                ]);

                return $result;
            }

            public function cleanupCurrentSessionReference(): void
            {
                $this->delegate->cleanupCurrentSessionReference();
            }

            public function normalizeIdempotencyKey(string $key): string
            {
                return $this->delegate->normalizeIdempotencyKey($key);
            }
        };

        $realBusinessDateDependency = new \Modules\Operations\FrontDesk\Services\FrontDeskBusinessDateDependencyService(
            app(\Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService::class)
        );
        $observedBusinessDateDependency = new class($realBusinessDateDependency, $telemetry) extends \Modules\Operations\FrontDesk\Services\FrontDeskBusinessDateDependencyService {
            public function __construct(
                private readonly \Modules\Operations\FrontDesk\Services\FrontDeskBusinessDateDependencyService $delegate,
                private readonly object $telemetry,
            ) {}

            public function project(\Modules\Foundation\User\Models\User $actor): array
            {
                $result = $this->delegate->project($actor);
                $this->telemetry->record('business_date_projection', [
                    'status' => $result['status'] ?? null,
                    'property_resolved' => is_string($result['property_id'] ?? null) && $result['property_id'] !== '',
                    'business_date_resolved' => is_string($result['business_date'] ?? null) && $result['business_date'] !== '',
                ]);

                return $result;
            }
        };

        $realOperationalLock = new \Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService();
        $observedOperationalLock = new class($realOperationalLock, $telemetry) extends \Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService {
            public function __construct(
                private readonly \Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService $delegate,
                private readonly object $telemetry,
            ) {}

            public function acquire(string $companyId, string $propertyId, array $expectedEvidence): \Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext
            {
                $context = $this->delegate->acquire($companyId, $propertyId, $expectedEvidence);
                $this->telemetry->record('postgresql_transaction', [
                    'postgres_backend_pid' => $context->postgres_backend_pid,
                    'postgres_transaction_id' => $context->postgres_transaction_id,
                    'property_match' => $context->property_id === $propertyId,
                    'business_date_match' => $context->business_date === (string) ($expectedEvidence['business_date'] ?? ''),
                ]);

                return $context;
            }

            public function assertIssuedForCurrentTransaction(\Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext $context): void
            {
                $this->delegate->assertIssuedForCurrentTransaction($context);
            }
        };

        $realNightAudit = new \Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService($realOperationalLock);
        $observedNightAudit = new class($realNightAudit, $telemetry) extends \Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService {
            public function __construct(
                private readonly \Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService $delegate,
                private readonly object $telemetry,
            ) {}

            public function attest(\Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext $context): \Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation
            {
                $result = $this->delegate->attest($context);
                $this->telemetry->record('na_a2', [
                    'status' => $result->status,
                    'transaction_bound' => $result->transaction_bound,
                    'property_match' => $result->property_id === $context->property_id,
                ]);

                return $result;
            }
        };

        $realFinancialAttestation = new \Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService(
            app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutFinancialEvaluationService::class),
            $realOperationalLock,
            app(\Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort::class),
            app(\Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort::class),
            app(\Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort::class),
        );
        $observedFinancialAttestation = new class($realFinancialAttestation, $telemetry) extends \Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService {
            public function __construct(
                private readonly \Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService $delegate,
                private readonly object $telemetry,
            ) {}

            public function attest(\Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext $operationalContext, string $frontDeskStayId): \Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation
            {
                $result = $this->delegate->attest($operationalContext, $frontDeskStayId);
                $this->telemetry->record('glf_e', [
                    'status' => $result->status->value,
                    'transaction_bound' => $result->transaction_bound,
                    'stay_match' => $result->front_desk_stay_id === $frontDeskStayId,
                ]);

                return $result;
            }

            public function assertIssuedForCurrentTransaction(\Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext $operationalContext, \Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation $attestation): void
            {
                $this->delegate->assertIssuedForCurrentTransaction($operationalContext, $attestation);
            }
        };

        $realCashierAttestation = new \Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService(
            $realOperationalLock,
            $realFinancialAttestation
        );
        $observedCashierAttestation = new class($realCashierAttestation, $telemetry) extends \Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService {
            public function __construct(
                private readonly \Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService $delegate,
                private readonly object $telemetry,
            ) {}

            public function attest(\Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext $operationalContext, \Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation $financialAttestation): \Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutTerminalObligationAttestation
            {
                $result = $this->delegate->attest($operationalContext, $financialAttestation);
                $this->telemetry->record('gc_a2', [
                    'status' => $result->status->value,
                    'transaction_bound' => $result->transaction_bound,
                    'stay_match' => $result->front_desk_stay_id === $financialAttestation->front_desk_stay_id,
                ]);

                return $result;
            }

            public function assertIssuedForCurrentTransaction(\Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext $operationalContext, \Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation $financialAttestation, \Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutTerminalObligationAttestation $cashierAttestation): void
            {
                $this->delegate->assertIssuedForCurrentTransaction($operationalContext, $financialAttestation, $cashierAttestation);
            }
        };

        app()->instance(\Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::class, $observedAuthorization);
        app()->instance(\Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService::class, $observedConfirmation);
        app()->instance(\Modules\Operations\FrontDesk\Services\FrontDeskBusinessDateDependencyService::class, $observedBusinessDateDependency);
        app()->instance(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class, $observedOperationalLock);
        app()->instance(\Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService::class, $observedNightAudit);
        app()->instance(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class, $observedFinancialAttestation);
        app()->instance(\Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService::class, $observedCashierAttestation);
        app()->forgetInstance(\Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService::class);

        DB::listen(fn (\Illuminate\Database\Events\QueryExecuted $query) => $telemetry->recordQuery($query));

        return $telemetry;
    }

    private function assertScenarioIRetryRuntimeTelemetry(object $telemetry, int $expectedAttempts, bool $requireThreeDistinctTransactions = false): void
    {
        $evidence = json_encode($telemetry->publicEvidence(), JSON_UNESCAPED_SLASHES);
        $boundaries = [
            'authorization_current_actor_authority',
            'current_company_property_resolution',
            'requested_stay_resolution',
            'confirmation_preflight',
            'business_date_projection',
            'postgresql_transaction',
            'na_a2',
            'glf_e',
            'gc_a2',
            'final_confirmation_claim_attempt',
        ];

        for ($attempt = 1; $attempt <= $expectedAttempts; $attempt++) {
            foreach ($boundaries as $boundary) {
                $this->assertSame(1, $telemetry->eventCount($attempt, $boundary), "Boundary {$boundary} must occur once for attempt {$attempt}: {$evidence}");
            }

            $this->assertSame(1, $telemetry->queryCount($attempt, 'committed_replay_resolution'), "Committed replay query must occur once for attempt {$attempt}: {$evidence}");
            $this->assertGreaterThanOrEqual(1, $telemetry->queryCount($attempt, 'postgresql_transaction_id_query'), "Runtime txid_current() query evidence missing for attempt {$attempt}: {$evidence}");
        }

        $transactionIds = $telemetry->transactionIds();
        $this->assertCount($expectedAttempts, $transactionIds, $evidence);
        foreach ($transactionIds as $transactionId) {
            $this->assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $transactionId, $evidence);
        }

        if ($requireThreeDistinctTransactions) {
            $this->assertSame(3, count(array_unique($transactionIds)), $evidence);
        }
    }

    /**
     * @return array{function: string, trigger: string}
     */
    private function installCheckoutInsertSleepTrigger(int $seconds): array
    {
        $suffix = Str::lower(Str::random(10));
        $function = "p9_checkout_sleep_fn_{$suffix}";
        $trigger = "p9_checkout_sleep_trg_{$suffix}";

        DB::connection('pgsql_concurrency')->unprepared("
            CREATE FUNCTION {$function}() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep({$seconds});
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER {$trigger}
                BEFORE INSERT ON front_desk_checkout_executions
                FOR EACH ROW
                EXECUTE FUNCTION {$function}();
        ");

        return ['function' => $function, 'trigger' => $trigger];
    }

    /**
     * @param array{function: string, trigger: string} $sleep
     */
    private function dropCheckoutInsertSleepTrigger(array $sleep): void
    {
        DB::connection('pgsql_concurrency')->unprepared("
            DROP TRIGGER IF EXISTS {$sleep['trigger']} ON front_desk_checkout_executions;
            DROP FUNCTION IF EXISTS {$sleep['function']}();
        ");
    }

    private function waitForBackendPgSleep(int $backendPid, int $timeoutSeconds = 10): void
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $row = DB::connection('pgsql_concurrency')->selectOne(
                'SELECT wait_event_type, wait_event, state
                   FROM pg_stat_activity
                  WHERE pid = ?',
                [$backendPid]
            );

            if ($row && $row->wait_event_type === 'Timeout' && $row->wait_event === 'PgSleep') {
                return;
            }

            usleep(100000);
        }

        $this->fail("Checkout backend {$backendPid} did not reach the disposable pg_sleep trigger.");
    }

    public function test_scenario_a_same_stay_same_key_one_commits_one_replays_with_real_blocking(): void
    {
        $f = $this->createCheckoutFixture('A01', 'p9-iso-key-A');
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold', $f);
            $locked = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidA = (int) $locked['backend_pid'];
            $this->assertGreaterThan(0, $pidA);

            $c->spawnWorker('execute_blocked', $f);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidB = (int) $ready['backend_pid'];
            $this->assertGreaterThan(0, $pidB);
            $this->assertNotSame($pidA, $pidB);

            $this->assertTrue($c->proveBlocking($pidB, $pidA, self::BLOCKING_PROOF_TIMEOUT_SECONDS), 'Worker B must block behind Worker A');

            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');
            $winner = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'committed');
            $loser = $this->assertCleanWorkerResult($c->waitForWorkerResult(1, self::WORKER_RESULT_TIMEOUT_SECONDS), 'executed');
            $this->assertFalse($winner['replayed'] ?? true, json_encode($winner, JSON_UNESCAPED_SLASHES));
            $this->assertTrue($loser['replayed'] ?? false, json_encode($loser, JSON_UNESCAPED_SLASHES));
            $this->assertSame($winner['checkout_execution_id'] ?? null, $loser['checkout_execution_id'] ?? null);
            $this->assertSame($winner['handoff_id'] ?? null, $loser['handoff_id'] ?? null);

            $this->assertExactRuntimeCounts(1);
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario B: same stay, different keys ═══

    public function test_scenario_b_same_stay_different_keys_one_wins_one_already_completed(): void
    {
        $fA = $this->createCheckoutFixture('B01', 'p9-iso-key-B1');
        $fB = $this->createCheckoutFixture('B01', 'p9-iso-key-B2', $fA['front_desk_stay_id']);
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold', $fA);
            $locked = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidA = (int) ($locked['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidA);
            $c->spawnWorker('execute_blocked', $fB);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidB = (int) ($ready['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidB);
            $this->assertNotSame($pidA, $pidB);
            $this->assertTrue($c->proveBlocking($pidB, $pidA, self::BLOCKING_PROOF_TIMEOUT_SECONDS), 'Same-stay different-key worker must wait on the winning checkout worker.');
            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');
            $winner = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'committed');
            $loser = $this->assertCleanWorkerResult($c->waitForWorkerResult(1, self::WORKER_RESULT_TIMEOUT_SECONDS), 'domain_error', FrontDeskCheckoutExecutionService::ERROR_ALREADY_COMPLETED);
            $this->assertNotSame($winner['php_pid'] ?? null, $loser['php_pid'] ?? null);
            $this->assertNotSame($winner['backend_pid'] ?? null, $loser['backend_pid'] ?? null);
            $this->assertFalse($winner['replayed'] ?? true, json_encode($winner, JSON_UNESCAPED_SLASHES));
            $this->assertSame($fA['front_desk_stay_id'], $winner['front_desk_stay_id'] ?? null);
            $this->assertSame($fB['front_desk_stay_id'], $loser['front_desk_stay_id'] ?? null);
            $this->assertExactRuntimeCounts(1);
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($fA['front_desk_stay_id'])->status);
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario C: same key, different stays ═══

    public function test_scenario_c_same_key_different_stays_one_wins_one_idempotency_conflict(): void
    {
        $fA = $this->createCheckoutFixture('C01', 'p9-iso-key-C');
        $fB = $this->createCheckoutFixture('C02', 'p9-iso-key-C');
        $this->assertNotSame($fA['front_desk_stay_id'], $fB['front_desk_stay_id']);
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        $sleep = $this->installCheckoutInsertSleepTrigger(3);
        try {
            $c->spawnWorker('lock_hold', $fA);
            $locked = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidA = (int) ($locked['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidA);
            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');
            $this->waitForBackendPgSleep($pidA, 15);
            $c->spawnWorker('execute_blocked', $fB);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidB = (int) ($ready['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidB);
            $this->assertNotSame($pidA, $pidB);
            $this->assertTrue($c->proveBlocking($pidB, $pidA, self::BLOCKING_PROOF_TIMEOUT_SECONDS), 'Same-key different-stay worker must contend on the property-scoped idempotency path.');
            $workerAResult = $c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS);
            $workerBResult = $c->waitForWorkerResult(1, self::WORKER_RESULT_TIMEOUT_SECONDS);
            $workerA = $this->assertCleanWorkerResult($workerAResult, $workerAResult['payload']['result'] ?? '');
            $workerB = $this->assertCleanWorkerResult($workerBResult, $workerBResult['payload']['result'] ?? '');
            $results = [$workerA['result'] ?? null, $workerB['result'] ?? null];
            $this->assertContains('domain_error', $results, json_encode([$workerA, $workerB], JSON_UNESCAPED_SLASHES));
            $this->assertTrue(
                in_array('committed', $results, true) || in_array('executed', $results, true),
                json_encode([$workerA, $workerB], JSON_UNESCAPED_SLASHES)
            );
            $loser = ($workerA['result'] ?? null) === 'domain_error' ? $workerA : $workerB;
            $winner = ($workerA['result'] ?? null) === 'domain_error' ? $workerB : $workerA;
            $this->assertSame(FrontDeskCheckoutExecutionService::ERROR_IDEMPOTENCY_CONFLICT, $loser['message'] ?? null);
            $this->assertStringNotContainsString((string) ($winner['front_desk_stay_id'] ?? ''), json_encode($loser, JSON_UNESCAPED_SLASHES));
            $this->assertFalse($winner['replayed'] ?? true, json_encode($winner, JSON_UNESCAPED_SLASHES));
            $this->assertContains($winner['front_desk_stay_id'] ?? null, [$fA['front_desk_stay_id'], $fB['front_desk_stay_id']]);
            $this->assertContains($loser['front_desk_stay_id'] ?? null, [$fA['front_desk_stay_id'], $fB['front_desk_stay_id']]);
            $this->assertNotSame($winner['front_desk_stay_id'] ?? null, $loser['front_desk_stay_id'] ?? null);
            $this->assertExactRuntimeCounts(1);
            $checkedOut = FrontDeskStay::on('pgsql_concurrency')->where('status', FrontDeskStayStatusEnum::CheckedOut->value)->count();
            $inHouse = FrontDeskStay::on('pgsql_concurrency')->where('status', FrontDeskStayStatusEnum::InHouse->value)->count();
            $this->assertSame(1, $checkedOut);
            $this->assertSame(1, $inHouse);
        } finally {
            $c->terminateAllWorkers();
            $this->dropCheckoutInsertSleepTrigger($sleep);
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario D: checkout vs Night Audit ═══

    public function test_scenario_d1_real_night_audit_start_waits_behind_checkout_held_locks(): void
    {
        $f = $this->createCheckoutFixture('D01', 'p9-iso-key-D1');
        $sleep = $this->installCheckoutInsertSleepTrigger(4);
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        $na = null;

        try {
            $c->spawnWorker('execute', $f);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_before_execute.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $checkoutPid = (int) ($ready['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $checkoutPid);
            $this->waitForBackendPgSleep($checkoutPid, 15);

            $na = $this->spawnNightAuditWorker('start', 'na');
            $this->waitForNightAuditBarrier($na['barrier'], 'start-ready-na', $na['run_id']);
            $startReady = json_decode((string) file_get_contents($na['barrier'] . '-start-ready-na.json'), true) ?: [];
            $nightAuditPid = (int) ($startReady['pg_backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $nightAuditPid);
            $this->assertNotSame($checkoutPid, $nightAuditPid);
            $this->assertTrue($c->proveBlocking($nightAuditPid, $checkoutPid, self::BLOCKING_PROOF_TIMEOUT_SECONDS), 'Night Audit start must block behind checkout-held Property/Business-Date locks.');

            $checkout = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'executed');
            $this->assertFalse($checkout['replayed'] ?? true, json_encode($checkout, JSON_UNESCAPED_SLASHES));
            $exit = $this->waitProcess($na['proc'], 10);
            $naResult = $this->readNightAuditResult($na['result']);
            $this->assertSame(0, $exit, json_encode($naResult, JSON_UNESCAPED_SLASHES));
            $this->assertSame(NightAuditRunStatusEnum::InProgress->value, $naResult['status'] ?? null, json_encode($naResult, JSON_UNESCAPED_SLASHES));
            $this->assertSame(1, $naResult['active_count'] ?? null);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(1, NightAuditRun::on('pgsql_concurrency')->withoutGlobalScopes()->where('status', NightAuditRunStatusEnum::InProgress)->count());
            $this->assertRealTerminalStatuses($f['front_desk_stay_id']);
        } finally {
            $c->terminateAllWorkers();
            $this->dropCheckoutInsertSleepTrigger($sleep);
            if (is_array($na) && isset($na['proc']) && is_resource($na['proc'])) {
                @proc_terminate($na['proc']);
                @proc_close($na['proc']);
            }
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    public function test_scenario_d2_real_active_night_audit_run_blocks_checkout(): void
    {
        $f = $this->createCheckoutFixture('D02', 'p9-iso-key-D2');
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        $na = $this->spawnNightAuditWorker('start', 'na-d2');

        try {
            $this->waitForNightAuditBarrier($na['barrier'], 'start-ready-na-d2', $na['run_id']);
            $naReady = json_decode((string) file_get_contents($na['barrier'] . '-start-ready-na-d2.json'), true) ?: [];
            $naBackendPid = (int) ($naReady['pg_backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $naBackendPid);
            $this->assertSame(0, $this->waitProcess($na['proc'], 15));
            $naResult = $this->readNightAuditResult($na['result']);
            $this->assertSame(NightAuditRunStatusEnum::InProgress->value, $naResult['status'] ?? null, json_encode($naResult, JSON_UNESCAPED_SLASHES));
            $this->assertSame(1, $naResult['active_count'] ?? null);

            $c->spawnWorker('execute', $f);
            $checkoutReady = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_before_execute.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $checkoutBackendPid = (int) ($checkoutReady['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $checkoutBackendPid);
            $this->assertNotSame($naBackendPid, $checkoutBackendPid);

            $checkout = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'domain_error', FrontDeskCheckoutExecutionService::ERROR_NIGHT_AUDIT_ACTIVE);
            $this->assertNotSame($naReady['php_pid'] ?? null, $checkout['php_pid'] ?? null);
        } finally {
            $c->terminateAllWorkers();
        }

        $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
        $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
        $this->assertSame(0, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
        $this->assertSame(0, DB::connection('pgsql_concurrency')->table('audit_logs')->where('event', 'front_desk_checkout_completed')->count());
        $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
    }

    // ═══ Scenario E: confirmation expiry (create pre-expired issuance) ═══

    public function test_scenario_e_expired_confirmation_fails_closed(): void
    {
        // Create a fixture with an already-expired confirmation
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        try {
            $guest = Guest::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'guest_code' => 'G-' . Str::upper(Str::random(6)), 'full_name' => 'Guest ' . Str::random(4), 'guest_type' => 'individual']);
            $res = Reservation::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'primary_guest_id' => $guest->id, 'reservation_number' => 'R-' . Str::upper(Str::random(6)), 'arrival_date' => Carbon::now()->toDateString(), 'departure_date' => Carbon::now()->addDays(2)->toDateString(), 'nights' => 2, 'reservation_source' => 'direct', 'status' => 'checked_in', 'reserved_room_type' => 'standard']);
            $stay = FrontDeskStay::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'reservation_id' => $res->id, 'guest_id' => $res->primary_guest_id, 'status' => FrontDeskStayStatusEnum::InHouse->value, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
            $folio = new Folio();
            $folio->setConnection('pgsql_concurrency');
            $folio->forceFill([
                'property_id' => $this->property->id,
                'folio_number' => 'P9-' . Str::upper(Str::random(8)),
                'reservation_id' => $res->id,
                'guest_id' => $res->primary_guest_id,
                'status' => FolioStatusEnum::Open->value,
                'currency' => $this->property->currency ?? 'USD',
                'window_number' => 1,
                'opening_idempotency_key' => 'p9-folio-' . Str::ulid(),
                'total_charges' => '0.00',
                'total_payments' => '0.00',
                'total_deposits' => '0.00',
                'total_ar_transfers' => '0.00',
                'balance' => '0.00',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
            ])->save();
            $occ = Carbon::now();
            FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'front_desk_stay_id' => $stay->id, 'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id, 'final_review_status' => FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady->value, 'idempotency_key' => 'review-' . Str::ulid(), 'source_hash' => hash('sha256', implode('|', [$stay->id, 'CHECKOUT_FINAL_REVIEW_READY', '', $occ->toISOString()])), 'occurred_at' => $occ, 'created_by' => $this->actor->id, 'created_at' => $occ]);

            $idempotencyKey = 'p9-iso-key-E';
            $issId = (string) Str::ulid(); $ident = (string) Str::ulid();
            $sessId = session()->getId(); $sessFp = CheckoutSensitiveConfirmationService::fingerprintSession($sessId);
            $confAt = Carbon::now()->subMinutes(20);
            $expAt = Carbon::now()->subMinutes(5);
            $fp = hash('sha256', implode('|', [CheckoutSensitiveConfirmationService::INTENT, $ident, $this->actor->id, $this->property->company_id, $this->property->id, $stay->id, $idempotencyKey, $sessFp, $confAt->toISOString(), $expAt->toISOString()]));
            DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_issuances')->insert(['id' => $issId, 'confirmation_identity' => $ident, 'intent' => CheckoutSensitiveConfirmationService::INTENT, 'actor_id' => $this->actor->id, 'company_id' => $this->property->company_id, 'property_id' => $this->property->id, 'front_desk_stay_id' => $stay->id, 'checkout_idempotency_key' => $idempotencyKey, 'session_fingerprint' => $sessFp, 'confirmation_fingerprint' => $fp, 'confirmed_at' => $confAt, 'expires_at' => $expAt, 'created_at' => $confAt]);

            $fixture = [
                'front_desk_stay_id' => $stay->id, 'checkout_idempotency_key' => $idempotencyKey,
                'issuance_id' => $issId, 'confirmation_identity' => $ident,
                'confirmation_fingerprint' => $fp, 'session_fingerprint' => $sessFp,
                'confirmed_at' => $confAt->toISOString(), 'expires_at' => $expAt->toISOString(),
            ];
            $this->actingAsConcurrencyActor($fixture);

            try {
                $this->withConcurrencyConnection(fn () => app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $stay->id, $idempotencyKey));
                $this->fail('Expired confirmation must throw');
            } catch (\DomainException $e) {
                // Expected — confirmation expired
            }
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($stay->id)->status);
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    // ═══ Scenario G: response-loss replay ═══

    public function test_scenario_e_valid_at_start_then_expires_while_blocked_fails_closed(): void
    {
        $f = $this->createCheckoutFixture('E02', 'p9-iso-key-E2', ttlSeconds: 30);
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold_rollback', $f);
            $locked = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidA = (int) ($locked['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidA);

            $c->spawnWorker('execute_blocked_after_release', $f);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidB = (int) ($ready['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidB);
            $this->waitUntilConcurrencyDbClockPasses(Carbon::parse($f['expires_at'])->subSeconds(2)->toISOString(), 45);
            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_b');
            $this->assertTrue($c->proveBlocking($pidB, $pidA, self::BLOCKING_PROOF_TIMEOUT_SECONDS), 'Worker B must begin with a valid confirmation and then block behind Worker A.');

            $this->waitUntilConcurrencyDbClockPasses($f['expires_at'], 10);
            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');

            $holder = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'rolled_back');
            $blocked = $this->assertCleanWorkerResult($c->waitForWorkerResult(1, self::WORKER_RESULT_TIMEOUT_SECONDS), 'domain_error', CheckoutSensitiveConfirmationService::ERROR_EXPIRED);
            $this->assertSame($f['front_desk_stay_id'], $holder['front_desk_stay_id'] ?? null);
            $this->assertSame($f['front_desk_stay_id'], $blocked['front_desk_stay_id'] ?? null);
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    private function waitUntilConcurrencyDbClockPasses(string $expiresAt, int $timeoutSeconds): void
    {
        $expires = Carbon::parse($expiresAt)->utc();
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $dbNow = Carbon::parse(
                DB::connection('pgsql_concurrency')
                    ->selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc")
                    ->wall_clock_utc
            )->utc();

            if ($dbNow->greaterThan($expires)) {
                $this->assertTrue(true);
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail('PostgreSQL clock did not pass checkout confirmation expiry before release.');
    }

    public function test_scenario_f_lock_holder_rolls_back_then_blocked_checkout_commits_once(): void
    {
        $f = $this->createCheckoutFixture('F01', 'p9-iso-key-F');
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold_rollback', $f);
            $locked = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidA = (int) ($locked['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidA);

            $c->spawnWorker('execute_blocked', $f);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pidB = (int) ($ready['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pidB);
            $this->assertTrue($c->proveBlocking($pidB, $pidA, self::BLOCKING_PROOF_TIMEOUT_SECONDS), 'Worker B must block until Worker A rolls back.');

            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');

            $holder = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'rolled_back');
            $winner = $this->assertCleanWorkerResult($c->waitForWorkerResult(1, self::WORKER_RESULT_TIMEOUT_SECONDS), 'executed');
            $this->assertSame($f['front_desk_stay_id'], $holder['front_desk_stay_id'] ?? null);
            $this->assertSame($f['front_desk_stay_id'], $winner['front_desk_stay_id'] ?? null);
            $this->assertFalse($winner['replayed'] ?? true, json_encode($winner, JSON_UNESCAPED_SLASHES));
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
            $this->assertRealTerminalStatuses($f['front_desk_stay_id']);
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    public function test_scenario_g_response_loss_replay_returns_existing_execution(): void
    {
        $f = $this->createCheckoutFixture('G01', 'p9-iso-key-G');
        $this->actingAsConcurrencyActor($f);
        $r1 = $this->withConcurrencyConnection(fn () => app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key']));
        $this->assertFalse($r1->replayed);
        $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR, $r1->nightAuditStatus);
        $this->assertSame(GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady->value, $r1->pmsTerminalFinancialStatus);
        $this->assertSame(GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear->value, $r1->generalCashierTerminalObligationStatus);
        $execId = $r1->checkoutExecutionId; $execCount = DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count();
        $r2 = $this->withConcurrencyConnection(fn () => app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key']));
        $this->assertTrue($r2->replayed);
        $this->assertSame($execId, $r2->checkoutExecutionId);
        $this->assertSame($execCount, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
        $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
        $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
        $this->assertSame(1, DB::connection('pgsql_concurrency')->table('audit_logs')->where('event', 'front_desk_checkout_completed')->count());
        $this->assertRealTerminalStatuses($f['front_desk_stay_id']);
    }

    // ═══ Scenario H: different Properties ═══

    public function test_scenario_h_different_properties_independent_execution_distinct_pids(): void
    {
        $propertyB = $this->withConcurrencyConnection(function (): Property {
            $property = Property::on('pgsql_concurrency')->create(['company_id' => $this->company->id, 'name' => 'P9 Iso Prop 2', 'slug' => 'p9-iso-prop2-' . Str::lower(Str::random(6)), 'code' => 'P9J' . Str::upper(Str::random(2)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
            $this->actor->properties()->attach($property->id, ['is_default' => false, 'status' => 'active', 'joined_at' => now()]);
            PropertyBusinessDate::on('pgsql_concurrency')->create(['property_id' => $property->id, 'business_date' => Carbon::now()->toDateString(), 'status' => PropertyBusinessDateStatusEnum::Open, 'is_open' => true, 'timezone_snapshot' => 'UTC', 'opened_by' => $this->actor->id, 'opened_at' => Carbon::now()]);

            return $property;
        });
        $f1 = $this->createCheckoutFixture('H01', 'p9-iso-key-H1');
        $f2 = $this->createCheckoutFixture('H02', 'p9-iso-key-H2', property: $propertyB);
        $f1['ready_marker'] = 'h1';
        $f2['ready_marker'] = 'h2';
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        $sleep = $this->installCheckoutInsertSleepTrigger(3);
        try {
            $c->spawnWorker('execute', $f1);
            $c->spawnWorker('execute', $f2);
            $ready1 = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_before_execute_h1.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $ready2 = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_before_execute_h2.json', self::WORKER_MARKER_TIMEOUT_SECONDS);
            $pid1 = (int) ($ready1['backend_pid'] ?? 0);
            $pid2 = (int) ($ready2['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $pid1);
            $this->assertGreaterThan(0, $pid2);
            $this->assertNotSame($pid1, $pid2);
            $this->waitForBackendPgSleep($pid1, 15);
            $this->waitForBackendPgSleep($pid2, 15);
            $this->assertTrue($c->proveNoBlockingBetween($pid1, $pid2), 'Different-property checkout workers must not block each other.');
            $r1 = $this->assertCleanWorkerResult($c->waitForWorkerResult(0, self::WORKER_RESULT_TIMEOUT_SECONDS), 'executed');
            $r2 = $this->assertCleanWorkerResult($c->waitForWorkerResult(1, self::WORKER_RESULT_TIMEOUT_SECONDS), 'executed');
            $this->assertGreaterThan(0, $r1['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $r2['backend_pid'] ?? 0);
            $this->assertNotSame($r1['backend_pid'] ?? 0, $r2['backend_pid'] ?? 0, 'Distinct backend PIDs required');
            $this->assertNotSame($r1['php_pid'] ?? 0, $r2['php_pid'] ?? 0, 'Distinct PHP PIDs required');
            $this->assertSame($f1['property_id'], $r1['property_id'] ?? null);
            $this->assertSame($f2['property_id'], $r2['property_id'] ?? null);
            $this->assertNotSame($r1['property_id'] ?? null, $r2['property_id'] ?? null, 'Different-property executions must keep distinct property evidence.');
            $this->assertFalse($r1['replayed'] ?? true, json_encode($r1, JSON_UNESCAPED_SLASHES));
            $this->assertFalse($r2['replayed'] ?? true, json_encode($r2, JSON_UNESCAPED_SLASHES));
            $this->assertExactRuntimeCounts(2);
            $this->assertSame(
                FrontDeskStayStatusEnum::CheckedOut,
                FrontDeskStay::on('pgsql_concurrency')->withoutGlobalScopes()->whereKey($f1['front_desk_stay_id'])->value('status')
            );
            $this->assertSame(
                FrontDeskStayStatusEnum::CheckedOut,
                FrontDeskStay::on('pgsql_concurrency')->withoutGlobalScopes()->whereKey($f2['front_desk_stay_id'])->value('status')
            );
        } finally {
            $c->terminateAllWorkers();
            $this->dropCheckoutInsertSleepTrigger($sleep);
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario I: bounded retry ═══

    public function test_scenario_i1_runtime_sqlstate_40001_retries_then_commits_once(): void
    {
        $f = $this->createCheckoutFixture('I01', 'p9-iso-key-I1');
        $this->actingAsConcurrencyActor($f);
        $fault = $this->installCheckoutInsertSqlstateFault('40001', 2);
        $runtimeTelemetry = $this->installScenarioIRetryRuntimeTelemetryObservers();

        try {
            $result = $this->withConcurrencyConnection(
                fn () => app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key'])
            );

            $this->assertFalse($result->replayed);
            $this->assertSame(3, $this->checkoutFaultAttemptCount($fault));
            $this->assertSame([
                ['attempt' => 1, 'sqlstate' => '40001', 'raised' => true],
                ['attempt' => 2, 'sqlstate' => '40001', 'raised' => true],
                ['attempt' => 3, 'sqlstate' => 'COMMITTED_INSERT', 'raised' => false],
            ], $this->checkoutFaultTelemetry($fault));
            $this->assertScenarioIRetryRuntimeTelemetry($runtimeTelemetry, 3, requireThreeDistinctTransactions: true);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
            $this->assertRealTerminalStatuses($f['front_desk_stay_id']);
        } finally {
            $runtimeTelemetry->disable();
            $this->dropCheckoutInsertSqlstateFault($fault);
        }
    }

    public function test_scenario_i2_runtime_sqlstate_40001_exhausts_three_attempts_without_partial_writes(): void
    {
        $f = $this->createCheckoutFixture('I02', 'p9-iso-key-I2');
        $this->actingAsConcurrencyActor($f);
        $fault = $this->installCheckoutInsertSqlstateFault('40001', 3);
        $runtimeTelemetry = $this->installScenarioIRetryRuntimeTelemetryObservers();

        try {
            try {
                $this->withConcurrencyConnection(
                    fn () => app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key'])
                );
                $this->fail('SQLSTATE 40001 must bubble after the bounded retry budget is exhausted.');
            } catch (QueryException $exception) {
                $this->assertSame('40001', (string) ($exception->errorInfo[0] ?? $exception->getCode()));
            }

            $this->assertSame(3, $this->checkoutFaultAttemptCount($fault));
            $this->assertSame([
                ['attempt' => 1, 'sqlstate' => '40001', 'raised' => true],
                ['attempt' => 2, 'sqlstate' => '40001', 'raised' => true],
                ['attempt' => 3, 'sqlstate' => '40001', 'raised' => true],
            ], $this->checkoutFaultTelemetry($fault));
            $this->assertScenarioIRetryRuntimeTelemetry($runtimeTelemetry, 3);
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
        } finally {
            $runtimeTelemetry->disable();
            $this->dropCheckoutInsertSqlstateFault($fault);
        }
    }

    public function test_scenario_i3_runtime_nonretryable_sqlstate_is_not_retried(): void
    {
        $f = $this->createCheckoutFixture('I03', 'p9-iso-key-I3');
        $this->actingAsConcurrencyActor($f);
        $fault = $this->installCheckoutInsertSqlstateFault('P0001', 10);
        $runtimeTelemetry = $this->installScenarioIRetryRuntimeTelemetryObservers();

        try {
            try {
                $this->withConcurrencyConnection(
                    fn () => app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key'])
                );
                $this->fail('Non-retryable SQLSTATE must not be retried.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', (string) ($exception->errorInfo[0] ?? $exception->getCode()));
            }

            $this->assertSame(1, $this->checkoutFaultAttemptCount($fault));
            $this->assertSame([
                ['attempt' => 1, 'sqlstate' => 'P0001', 'raised' => true],
            ], $this->checkoutFaultTelemetry($fault));
            $this->assertScenarioIRetryRuntimeTelemetry($runtimeTelemetry, 1);
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
        } finally {
            $runtimeTelemetry->disable();
            $this->dropCheckoutInsertSqlstateFault($fault);
        }
    }

    // ═══ No global application lock ═══

    public function test_no_global_application_lock(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));
        $this->assertStringNotContainsString('Cache::lock', $source);
        $this->assertStringNotContainsString('GET_LOCK', $source);
        $this->assertStringNotContainsString('pg_advisory_lock', $source);
    }
}
