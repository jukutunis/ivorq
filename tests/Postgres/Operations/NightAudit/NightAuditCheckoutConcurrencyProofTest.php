<?php

namespace Tests\Postgres\Operations\NightAudit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Modules\Operations\NightAudit\Services\NightAuditBusinessDateDependencyService;
use Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService;
use Modules\Operations\NightAudit\Services\NightAuditRunStartService;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class NightAuditCheckoutConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Company $company;
    private Property $property;
    private User $actor;
    private PropertyBusinessDate $businessDate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
            NightAuditAuthorizationService::ABORT_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->createAuthorizedContext();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_checkout_participant_clear_serializes_night_audit_start_until_commit(): void
    {
        $dir = $this->tempDir();
        $runId = (string) Str::ulid();
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';

        try {
            $a = $this->spawn($dir, $barrier, $runId, 'a', 'participant_hold', $this->actor, $this->property, $this->businessDate);
            $this->waitForBarrier($barrier, 'locks-held-a', $runId);

            $b = $this->spawn($dir, $barrier, $runId, 'b', 'start', $this->actor, $this->property, $this->businessDate);
            $this->waitForBarrier($barrier, 'start-ready-b', $runId);
            $this->assertFalse($this->waitForResult($b['result'], 700), 'Start completed while participant locks were held.');
            $this->assertSame(0, NightAuditRun::withoutGlobalScopes()->where('status', NightAuditRunStatusEnum::InProgress)->count());

            file_put_contents($barrier . '-release-a.json', json_encode(['run_id' => $runId]));

            $this->assertSame(0, $this->waitProcess($a['proc'], 10));
            $this->assertSame(0, $this->waitProcess($b['proc'], 10));
            $aResult = $this->readResult($a['result']);
            $bResult = $this->readResult($b['result']);

            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR, $aResult['status']);
            $this->assertTrue($aResult['transaction_bound']);
            $this->assertSame(0, $aResult['active_count_while_held']);
            $this->assertNotSame($aResult['php_pid'], $bResult['php_pid']);
            $this->assertNotSame($aResult['pg_backend_pid'], $bResult['pg_backend_pid']);
            $this->assertSame(1, $bResult['active_count']);
            $this->assertSame(1, NightAuditRun::withoutGlobalScopes()->where('status', NightAuditRunStatusEnum::InProgress)->count());
        } finally {
            $this->cleanup($dir, [$a['proc'] ?? null, $b['proc'] ?? null]);
        }
    }

    public function test_active_first_participant_reports_active_with_zero_writes(): void
    {
        app(NightAuditRunStartService::class)->start($this->actor);
        $before = DB::table('night_audit_runs')->count();

        DB::transaction(function () use ($before): void {
            $context = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class)
                ->acquire($this->company->id, $this->property->id, $this->businessDateEvidence());
            $attestation = app(NightAuditCheckoutConcurrencyGuardService::class)->attest($context);

            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_ACTIVE, $attestation->status);
            $this->assertTrue($attestation->close_lock_active);
            $this->assertSame($before, DB::table('night_audit_runs')->count());
        });
    }

    public function test_participant_rollback_persists_no_evidence_and_later_start_succeeds(): void
    {
        $dir = $this->tempDir();
        $runId = (string) Str::ulid();
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';

        try {
            $a = $this->spawn($dir, $barrier, $runId, 'a', 'participant_rollback', $this->actor, $this->property, $this->businessDate);
            $this->waitForBarrier($barrier, 'locks-held-a', $runId);
            file_put_contents($barrier . '-release-a.json', json_encode(['run_id' => $runId]));
            $this->assertSame(0, $this->waitProcess($a['proc'], 10));

            $result = $this->readResult($a['result']);
            $this->assertSame(NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR, $result['status']);
            $this->assertSame(0, NightAuditRun::withoutGlobalScopes()->count());

            $run = app(NightAuditRunStartService::class)->start($this->actor);
            $this->assertSame(NightAuditRunStatusEnum::InProgress, $run->status);
            $this->assertSame(1, NightAuditRun::withoutGlobalScopes()->count());
        } finally {
            $this->cleanup($dir, [$a['proc'] ?? null]);
        }
    }

    public function test_different_properties_do_not_block_each_other_through_global_lock(): void
    {
        $otherProperty = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $this->company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actor->properties()->attach($otherProperty->id, ['is_default' => false, 'status' => 'active', 'joined_at' => now()]);
        $otherBusinessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $otherProperty->id,
            'business_date' => '2026-07-17',
            'timezone_snapshot' => 'Asia/Makassar',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_by' => $this->actor->id,
            'opened_at' => Carbon::parse('2026-07-17 00:00:00', 'UTC'),
        ]);

        $dir = $this->tempDir();
        $runId = (string) Str::ulid();
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';

        try {
            $a = $this->spawn($dir, $barrier, $runId, 'a', 'participant_hold', $this->actor, $this->property, $this->businessDate);
            $this->waitForBarrier($barrier, 'locks-held-a', $runId);

            app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
            session(['current_property_id' => $otherProperty->id]);
            $run = app(NightAuditRunStartService::class)->start($this->actor);
            $this->assertSame($otherProperty->id, $run->property_id);
            $this->assertSame($otherBusinessDate->id, $run->property_business_date_id);

            file_put_contents($barrier . '-release-a.json', json_encode(['run_id' => $runId]));
            $this->assertSame(0, $this->waitProcess($a['proc'], 10));
            $this->assertSame(1, NightAuditRun::withoutGlobalScopes()->where('property_id', $otherProperty->id)->count());
            $this->assertSame(0, NightAuditRun::withoutGlobalScopes()->where('property_id', $this->property->id)->count());
        } finally {
            $this->cleanup($dir, [$a['proc'] ?? null]);
        }
    }

    private function createAuthorizedContext(): void
    {
        app(CurrentPropertyService::class)->clear();

        $this->company = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
        $this->property = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $this->company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actor = \Database\Factories\UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);
        $this->actor->givePermissionTo([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::VIEW_PERMISSION,
            NightAuditAuthorizationService::START_PERMISSION,
            NightAuditAuthorizationService::ABORT_PERMISSION,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session(['active_company_id' => $this->company->id, 'current_property_id' => $this->property->id]);
        auth()->login($this->actor);
        $this->actingAs($this->actor);

        $this->businessDate = PropertyBusinessDate::factory()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-17',
            'timezone_snapshot' => 'Asia/Makassar',
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'opened_by' => $this->actor->id,
            'opened_at' => Carbon::parse('2026-07-17 00:00:00', 'UTC'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function businessDateEvidence(?Property $property = null): array
    {
        $property ??= $this->property;
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        session(['current_property_id' => $property->id]);

        return app(NightAuditBusinessDateDependencyService::class)->project($this->actor);
    }

    /**
     * @return array{proc: resource, result: string}
     */
    private function spawn(string $dir, string $barrier, string $runId, string $workerId, string $mode, User $actor, Property $property, PropertyBusinessDate $businessDate): array
    {
        $resultFile = $dir . DIRECTORY_SEPARATOR . "result-{$workerId}.json";
        $stderrFile = $dir . DIRECTORY_SEPARATOR . "stderr-{$workerId}.txt";
        $argsFile = $dir . DIRECTORY_SEPARATOR . "args-{$workerId}.json";
        $evidence = $this->businessDateEvidence($property);

        file_put_contents($argsFile, json_encode([
            'worker_id' => $workerId,
            'mode' => $mode,
            'run_id' => $runId,
            'result_file' => $resultFile,
            'barrier' => $barrier,
            'company_id' => $this->company->id,
            'property_id' => $property->id,
            'property_business_date_id' => $businessDate->id,
            'actor_id' => $actor->id,
            'business_date_evidence' => $evidence,
            'test_now' => '2026-07-17T01:00:00Z',
        ]));

        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'NightAuditCheckoutConcurrencyWorker.php';
        $cmd = sprintf('%s %s %s', PHP_BINARY, escapeshellarg($worker), escapeshellarg($argsFile));
        $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
        $proc = proc_open($cmd, $spec, $pipes, base_path(), array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'ivorq_testing',
        ]));
        if (! is_resource($proc)) {
            $this->fail('Unable to spawn NA-A2 worker.');
        }
        fclose($pipes[0]);

        return ['proc' => $proc, 'result' => $resultFile];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'na-a2-conc-' . Str::lower(Str::random(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private function waitForBarrier(string $barrier, string $name, string $runId, int $timeoutMs = 10000): void
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

        $this->fail("Timed out waiting for barrier {$name}.");
    }

    private function waitForResult(string $path, int $timeoutMs): bool
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return true;
            }
            usleep(25000);
        }

        return false;
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

    /**
     * @return array<string, mixed>
     */
    private function readResult(string $path): array
    {
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $this->assertIsArray($payload, "Missing or malformed result {$path}");

        return $payload;
    }

    /**
     * @param array<int, mixed> $procs
     */
    private function cleanup(string $dir, array $procs): void
    {
        foreach ($procs as $proc) {
            if (is_resource($proc)) {
                $status = proc_get_status($proc);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($proc);
                }
                @proc_close($proc);
            }
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
