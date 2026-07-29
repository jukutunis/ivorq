<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use ManagesConcurrencyDatabase;

    private const WORKER_MARKER_TIMEOUT_SECONDS = 60;
    private const WORKER_RESULT_TIMEOUT_SECONDS = 60;
    private const BLOCKING_PROOF_TIMEOUT_SECONDS = 30;

    private bool $concurrencyDatabaseReady = false;

    private string $previousDefaultConnection;

    /**
     * @var array<int, string>
     */
    private array $markerDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefaultConnection = DB::getDefaultConnection();
        $this->setUpConcurrencyDatabase('ivorq_concurrency_p8_csc_');
        $this->concurrencyDatabaseReady = true;

        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousDefaultConnection);
        config(['database.default' => $this->previousDefaultConnection]);

        if ($this->concurrencyDatabaseReady) {
            $this->tearDownConcurrencyDatabase();
            $this->concurrencyDatabaseReady = false;
        }

        foreach ($this->markerDirs as $markerDir) {
            $this->removeDirectory($markerDir);
        }

        parent::tearDown();
    }

    public function test_same_confirmation_second_claim_blocks_then_receives_already_consumed_after_first_commits(): void
    {
        $fixture = $this->fixture('concurrency-commit');
        [$a, $aPipes] = $this->startWorker($fixture, 'hold_commit');
        $aMarker = $this->waitMarker($fixture['marker_dir'], 'a_locked');

        [$b, $bPipes] = $this->startWorker($fixture, 'claim');
        $bMarker = $this->waitMarker($fixture['marker_dir'], 'b_before_claim');

        $this->assertNotSame($aMarker['php_pid'], $bMarker['php_pid']);
        $this->assertNotSame($aMarker['backend_pid'], $bMarker['backend_pid']);
        $this->assertEventuallyBlockedBy((int) $bMarker['backend_pid'], (int) $aMarker['backend_pid']);

        file_put_contents($fixture['marker_dir'] . DIRECTORY_SEPARATOR . 'release_a', '1');

        $aResult = $this->waitProcess($a, $aPipes);
        $bResult = $this->waitProcess($b, $bPipes);

        $this->assertSame('committed', $aResult['result']);
        $this->assertSame('domain_error', $bResult['result']);
        $this->assertSame(CheckoutSensitiveConfirmationService::ERROR_ALREADY_CONSUMED, $bResult['message']);
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    public function test_same_confirmation_second_claim_blocks_then_commits_when_first_rolls_back(): void
    {
        $fixture = $this->fixture('concurrency-rollback');
        [$a, $aPipes] = $this->startWorker($fixture, 'hold_rollback');
        $aMarker = $this->waitMarker($fixture['marker_dir'], 'a_locked');

        [$b, $bPipes] = $this->startWorker($fixture, 'claim');
        $bMarker = $this->waitMarker($fixture['marker_dir'], 'b_before_claim');

        $this->assertEventuallyBlockedBy((int) $bMarker['backend_pid'], (int) $aMarker['backend_pid']);
        file_put_contents($fixture['marker_dir'] . DIRECTORY_SEPARATOR . 'release_a', '1');

        $aResult = $this->waitProcess($a, $aPipes);
        $bResult = $this->waitProcess($b, $bPipes);

        $this->assertSame('rolled_back', $aResult['result']);
        $this->assertSame('claimed', $bResult['result']);
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    public function test_waiting_claim_revalidates_expiry_after_first_rolls_back(): void
    {
        $fixture = $this->fixture('concurrency-expiry', now(), now()->addSeconds(2));
        [$a, $aPipes] = $this->startWorker($fixture, 'hold_rollback');
        $aMarker = $this->waitMarker($fixture['marker_dir'], 'a_locked');

        [$b, $bPipes] = $this->startWorker($fixture, 'claim');
        $bMarker = $this->waitMarker($fixture['marker_dir'], 'b_before_claim');

        $this->assertEventuallyBlockedBy((int) $bMarker['backend_pid'], (int) $aMarker['backend_pid']);
        usleep(2_500_000);
        file_put_contents($fixture['marker_dir'] . DIRECTORY_SEPARATOR . 'release_a', '1');

        $aResult = $this->waitProcess($a, $aPipes);
        $bResult = $this->waitProcess($b, $bPipes);

        $this->assertSame('rolled_back', $aResult['result']);
        $this->assertSame('domain_error', $bResult['result']);
        $this->assertSame(CheckoutSensitiveConfirmationService::ERROR_EXPIRED, $bResult['message']);
        $this->assertSame(0, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    public function test_different_issuance_identities_same_checkout_identity_allow_only_one_consumption(): void
    {
        $first = $this->fixture('same-checkout-identity');
        $second = $this->additionalIssuanceFixture($first);

        [$a, $aPipes] = $this->startWorker($first, 'claim');
        [$b, $bPipes] = $this->startWorker($second, 'claim');
        $this->waitMarker($first['marker_dir'], 'b_before_claim');
        $this->waitMarker($second['marker_dir'], 'b_before_claim');

        $aResult = $this->waitProcess($a, $aPipes);
        $bResult = $this->waitProcess($b, $bPipes);

        $results = [$aResult['result'], $bResult['result']];
        sort($results);
        $this->assertSame(['claimed', 'domain_error'], $results);
        $error = $aResult['result'] === 'domain_error' ? $aResult : $bResult;
        $this->assertSame(CheckoutSensitiveConfirmationService::ERROR_CHECKOUT_IDENTITY_CONSUMED, $error['message']);
        $this->assertSame(1, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    public function test_different_properties_do_not_serialize_and_both_consume(): void
    {
        $first = $this->fixture('property-a');
        $second = $this->fixture('property-b');

        [$a, $aPipes] = $this->startWorker($first, 'claim');
        [$b, $bPipes] = $this->startWorker($second, 'claim');
        $aMarker = $this->waitMarker($first['marker_dir'], 'b_before_claim');
        $bMarker = $this->waitMarker($second['marker_dir'], 'b_before_claim');

        $this->assertNotSame($aMarker['backend_pid'], $bMarker['backend_pid']);
        $this->assertNoBlockingPid((int) $aMarker['backend_pid']);
        $this->assertNoBlockingPid((int) $bMarker['backend_pid']);

        $aResult = $this->waitProcess($a, $aPipes);
        $bResult = $this->waitProcess($b, $bPipes);

        $this->assertSame('claimed', $aResult['result']);
        $this->assertSame('claimed', $bResult['result']);
        $this->assertSame(2, DB::table('checkout_sensitive_confirmation_consumptions')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $key, $confirmedAt = null, $expiresAt = null): array
    {
        Permission::firstOrCreate(['name' => FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = Company::create(['name' => 'P8 Concurrency Co', 'slug' => 'p8-conc-' . Str::lower(Str::random(6)), 'is_active' => true]);
        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'P8 Concurrency Property',
            'slug' => 'p8-conc-property-' . Str::lower(Str::random(6)),
            'code' => Str::upper(Str::random(4)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $actor = User::create(['name' => 'P8 Concurrency Actor', 'email' => 'p8-conc-' . Str::lower(Str::random(6)) . '@example.test', 'password' => bcrypt('password'), 'is_active' => true]);
        $actor->properties()->attach($property->id, ['is_default' => true, 'status' => 'active', 'joined_at' => now()]);
        $actor->givePermissionTo(FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);
        $stay = $this->stay($property, $actor);

        $issuanceId = (string) Str::ulid();
        $identity = (string) Str::ulid();
        $sessionId = Str::random(40);
        $sessionFingerprint = CheckoutSensitiveConfirmationService::fingerprintSession($sessionId);
        $fingerprint = hash('sha256', 'p8-concurrency-confirmation-' . $issuanceId);
        $confirmedAt ??= now();
        $expiresAt ??= now()->addMinutes(15);

        DB::table('checkout_sensitive_confirmation_issuances')->insert([
            'id' => $issuanceId,
            'confirmation_identity' => $identity,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $actor->id,
            'company_id' => $company->id,
            'property_id' => $property->id,
            'front_desk_stay_id' => $stay->id,
            'checkout_idempotency_key' => $key,
            'session_fingerprint' => $sessionFingerprint,
            'confirmation_fingerprint' => $fingerprint,
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ]);

        $markerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p8-csc-' . Str::lower((string) Str::ulid());
        mkdir($markerDir, 0777, true);
        $this->markerDirs[] = $markerDir;
        $fixture = [
            'database' => $this->concurrencyDb,
            'company_id' => $company->id,
            'property_id' => $property->id,
            'actor_id' => $actor->id,
            'front_desk_stay_id' => $stay->id,
            'checkout_idempotency_key' => $key,
            'issuance_id' => $issuanceId,
            'confirmation_identity' => $identity,
            'confirmation_fingerprint' => $fingerprint,
            'session_fingerprint' => $sessionFingerprint,
            'session_id' => $sessionId,
            'confirmed_at' => $confirmedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'marker_dir' => $markerDir,
        ];
        $fixture['path'] = $markerDir . DIRECTORY_SEPARATOR . 'fixture.json';
        file_put_contents($fixture['path'], json_encode($fixture, JSON_THROW_ON_ERROR));

        return $fixture;
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function additionalIssuanceFixture(array $fixture): array
    {
        $issuanceId = (string) Str::ulid();
        $identity = (string) Str::ulid();
        $sessionId = Str::random(40);
        $sessionFingerprint = CheckoutSensitiveConfirmationService::fingerprintSession($sessionId);
        $fingerprint = hash('sha256', 'p8-concurrency-confirmation-' . $issuanceId);
        $confirmedAt = now();
        $expiresAt = now()->addMinutes(15);

        DB::table('checkout_sensitive_confirmation_issuances')->insert([
            'id' => $issuanceId,
            'confirmation_identity' => $identity,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $fixture['actor_id'],
            'company_id' => $fixture['company_id'],
            'property_id' => $fixture['property_id'],
            'front_desk_stay_id' => $fixture['front_desk_stay_id'],
            'checkout_idempotency_key' => $fixture['checkout_idempotency_key'],
            'session_fingerprint' => $sessionFingerprint,
            'confirmation_fingerprint' => $fingerprint,
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ]);

        $markerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p8-csc-' . Str::lower((string) Str::ulid());
        mkdir($markerDir, 0777, true);
        $this->markerDirs[] = $markerDir;

        $second = $fixture;
        $second['issuance_id'] = $issuanceId;
        $second['confirmation_identity'] = $identity;
        $second['confirmation_fingerprint'] = $fingerprint;
        $second['session_fingerprint'] = $sessionFingerprint;
        $second['session_id'] = $sessionId;
        $second['confirmed_at'] = $confirmedAt->toISOString();
        $second['expires_at'] = $expiresAt->toISOString();
        $second['marker_dir'] = $markerDir;
        $second['path'] = $markerDir . DIRECTORY_SEPARATOR . 'fixture.json';
        file_put_contents($second['path'], json_encode($second, JSON_THROW_ON_ERROR));

        return $second;
    }

    private function stay(Property $property, User $actor): FrontDeskStay
    {
        $guest = Guest::create(['property_id' => $property->id, 'guest_code' => 'P8C-' . Str::upper(Str::random(5)), 'full_name' => 'P8 Concurrency Guest', 'guest_type' => 'individual']);
        $reservation = Reservation::create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'P8C-' . Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDay(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'standard',
        ]);

        return FrontDeskStay::create([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startWorker(array $fixture, string $scenario): array
    {
        $worker = base_path('tests/Postgres/Operations/FrontDesk/Support/P8CheckoutConfirmationConcurrencyWorker.php');
        $command = [PHP_BINARY, $worker, $fixture['path'], $scenario];
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, base_path());
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /**
     * @return array<string, mixed>
     */
    private function waitMarker(string $dir, string $name): array
    {
        $path = $dir . DIRECTORY_SEPARATOR . $name . '.json';
        $deadline = microtime(true) + self::WORKER_MARKER_TIMEOUT_SECONDS;
        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                $this->fail("Marker {$name} not written.");
            }
            usleep(50_000);
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertEventuallyBlockedBy(int $blockedBackendPid, int $blockingBackendPid): void
    {
        $deadline = microtime(true) + self::BLOCKING_PROOF_TIMEOUT_SECONDS;
        do {
            $row = DB::selectOne('SELECT pg_blocking_pids(?) AS blockers', [$blockedBackendPid]);
            $blockers = trim((string) $row->blockers, '{}');
            $ids = $blockers === '' ? [] : array_map('intval', explode(',', $blockers));
            if (in_array($blockingBackendPid, $ids, true)) {
                $this->assertTrue(true);
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail('pg_blocking_pids did not prove the expected blocker.');
    }

    private function assertNoBlockingPid(int $backendPid): void
    {
        $row = DB::selectOne('SELECT pg_blocking_pids(?) AS blockers', [$backendPid]);
        $this->assertSame('{}', (string) $row->blockers);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    /**
     * @param resource $process
     * @return array<string, mixed>
     */
    private function waitProcess($process, array $pipes): array
    {
        $deadline = microtime(true) + self::WORKER_RESULT_TIMEOUT_SECONDS;
        $stdout = '';
        $stderr = '';
        while (microtime(true) < $deadline) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);
            if (! $status['running']) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $this->assertSame(0, $exit, 'Worker failed: ' . trim($stdout . PHP_EOL . $stderr));
                $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsArray($payload);

                return $payload;
            }
            usleep(100_000);
        }

        proc_terminate($process);
        $this->fail('Worker timed out.');
    }
}
