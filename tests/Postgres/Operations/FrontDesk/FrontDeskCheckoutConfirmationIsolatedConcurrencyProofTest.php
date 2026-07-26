<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

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

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $key): array
    {
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
        $stay = $this->stay($property, $actor);

        $issuanceId = (string) Str::ulid();
        $identity = (string) Str::ulid();
        $sessionFingerprint = hash('sha256', 'p8-concurrency-session-' . $issuanceId);
        $fingerprint = hash('sha256', 'p8-concurrency-confirmation-' . $issuanceId);
        $confirmedAt = now();
        $expiresAt = now()->addMinutes(15);

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
        $fixture = [
            'company_id' => $company->id,
            'property_id' => $property->id,
            'actor_id' => $actor->id,
            'front_desk_stay_id' => $stay->id,
            'checkout_idempotency_key' => $key,
            'issuance_id' => $issuanceId,
            'confirmation_identity' => $identity,
            'confirmation_fingerprint' => $fingerprint,
            'session_fingerprint' => $sessionFingerprint,
            'confirmed_at' => $confirmedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'marker_dir' => $markerDir,
        ];
        $fixture['path'] = $markerDir . DIRECTORY_SEPARATOR . 'fixture.json';
        file_put_contents($fixture['path'], json_encode($fixture, JSON_THROW_ON_ERROR));

        return $fixture;
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
        $deadline = microtime(true) + 15;
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
        $deadline = microtime(true) + 15;
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

    /**
     * @param resource $process
     * @return array<string, mixed>
     */
    private function waitProcess($process, array $pipes): array
    {
        $deadline = microtime(true) + 20;
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
