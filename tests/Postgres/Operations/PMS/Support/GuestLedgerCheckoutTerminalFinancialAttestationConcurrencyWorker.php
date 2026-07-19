<?php

namespace Tests\Postgres\Operations\PMS\Support;

/**
 * GLF-E Concurrency Worker.
 *
 * Each worker runs in a separate PHP process.
 * The worker:
 *   1. Connects to PostgreSQL via Laravel.
 *   2. Opens a transaction.
 *   3. Performs the requested operation.
 *   4. Outputs a JSON-encoded result line.
 *   5. Exits with 0 on success, 1 on failure.
 *
 * Input is read from a shared file or env vars set by the coordinator.
 */
class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyWorker
{
    /**
     * Run a worker from CLI.
     *
     * Expects: php worker.php <base_path> <phpunit_xml> <mode> <data_json>
     *
     * Modes: 'attest', 'mutate', 'attest_other_property'
     */
    public static function run(): void
    {
        global $argv;

        $basePath = $argv[1] ?? '';
        $phpunitXml = $argv[2] ?? '';
        $mode = $argv[3] ?? 'attest';
        $dataJson = $argv[4] ?? '{}';

        if (empty($basePath) || empty($phpunitXml)) {
            fwrite(STDERR, json_encode(['error' => 'Missing base_path or phpunit_xml']) . "\n");
            exit(1);
        }

        chdir($basePath);

        // Bootstrap Laravel
        require $basePath . '/vendor/autoload.php';
        $app = require_once $basePath . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $data = json_decode($dataJson, true) ?: [];

        try {
            $result = match ($mode) {
                'attest' => self::doAttest($data),
                'mutate' => self::doMutate($data),
                'attest_other_property' => self::doAttestOtherProperty($data),
                'lock_source' => self::doLockSource($data),
                default => ['error' => "Unknown mode: {$mode}"],
            };

            echo json_encode($result) . "\n";
            exit(0);
        } catch (\Throwable $e) {
            echo json_encode([
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]) . "\n";
            exit(1);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function doAttest(array $data): array
    {
        $propertyId = $data['property_id'] ?? '';
        $companyId = $data['company_id'] ?? '';
        $stayId = $data['stay_id'] ?? '';
        $expectedEvidence = $data['expected_evidence'] ?? [];

        $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $companyId, $propertyId, $expectedEvidence, $stayId, $data) {
            $context = $lockService->acquire($companyId, $propertyId, $expectedEvidence);

            // Signal readiness by writing a marker
            self::signalReady($data['marker'] ?? null);

            // Wait for barrier signal if requested
            if (! empty($data['barrier_path'])) {
                self::waitForBarrier($data['barrier_path'], $data['barrier_timeout'] ?? 30);
            }

            $attestation = $attestService->attest($context, $stayId);

            // Hold transaction open if requested
            if (! empty($data['hold_until_path'])) {
                self::writeReadyAndWait($data['hold_until_path'], $data['hold_timeout'] ?? 30);
            }

            return [
                'status' => $attestation->status->value,
                'fingerprint' => $attestation->source_fingerprint,
                'pid' => getmypid(),
                'pg_backend_pid' => self::pgBackendPid(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function doMutate(array $data): array
    {
        $table = $data['table'] ?? '';
        $rowId = $data['row_id'] ?? '';
        $column = $data['column'] ?? '';
        $value = $data['value'] ?? '';

        return \Illuminate\Support\Facades\DB::transaction(function () use ($table, $rowId, $column, $value, $data) {
            // Signal readiness
            self::signalReady($data['marker'] ?? null);

            // Wait for barrier if requested
            if (! empty($data['barrier_path'])) {
                self::waitForBarrier($data['barrier_path'], $data['barrier_timeout'] ?? 30);
            }

            \Illuminate\Support\Facades\DB::table($table)
                ->where('id', $rowId)
                ->update([$column => $value]);

            // Hold transaction open if requested
            if (! empty($data['hold_until_path'])) {
                self::writeReadyAndWait($data['hold_until_path'], $data['hold_timeout'] ?? 30);
            }

            return [
                'mutated' => true,
                'table' => $table,
                'row_id' => $rowId,
                'pid' => getmypid(),
                'pg_backend_pid' => self::pgBackendPid(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function doAttestOtherProperty(array $data): array
    {
        $propertyId = $data['property_id'] ?? '';
        $companyId = $data['company_id'] ?? '';
        $stayId = $data['stay_id'] ?? '';
        $expectedEvidence = $data['expected_evidence'] ?? [];

        $lockService = app(\Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService::class);
        $attestService = app(\Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService::class);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($lockService, $attestService, $companyId, $propertyId, $expectedEvidence, $stayId, $data) {
            $context = $lockService->acquire($companyId, $propertyId, $expectedEvidence);

            // Signal readiness
            self::signalReady($data['marker'] ?? null);

            // Wait for barrier if requested
            if (! empty($data['barrier_path'])) {
                self::waitForBarrier($data['barrier_path'], $data['barrier_timeout'] ?? 30);
            }

            $attestation = $attestService->attest($context, $stayId);

            // Hold transaction open if requested
            if (! empty($data['hold_until_path'])) {
                self::writeReadyAndWait($data['hold_until_path'], $data['hold_timeout'] ?? 30);
            }

            return [
                'status' => $attestation->status->value,
                'pid' => getmypid(),
                'pg_backend_pid' => self::pgBackendPid(),
            ];
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function doLockSource(array $data): array
    {
        $table = $data['table'] ?? '';
        $rowId = $data['row_id'] ?? '';

        return \Illuminate\Support\Facades\DB::transaction(function () use ($table, $rowId, $data) {
            // Signal readiness
            self::signalReady($data['marker'] ?? null);

            // Wait for barrier if requested
            if (! empty($data['barrier_path'])) {
                self::waitForBarrier($data['barrier_path'], $data['barrier_timeout'] ?? 30);
            }

            \Illuminate\Support\Facades\DB::table($table)
                ->where('id', $rowId)
                ->lockForUpdate()
                ->first();

            // Hold transaction open if requested
            if (! empty($data['hold_until_path'])) {
                self::writeReadyAndWait($data['hold_until_path'], $data['hold_timeout'] ?? 30);
            }

            return [
                'locked' => true,
                'table' => $table,
                'row_id' => $rowId,
                'pid' => getmypid(),
                'pg_backend_pid' => self::pgBackendPid(),
            ];
        });
    }

    // ── Coordination helpers ─────────────────────────────────────────────

    private static function pgBackendPid(): int
    {
        $row = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid');
        return (int) ($row->pid ?? 0);
    }

    private static function signalReady(?string $markerPath): void
    {
        if ($markerPath !== null && $markerPath !== '') {
            file_put_contents($markerPath, getmypid() . "\n" . self::pgBackendPid());
        }
    }

    private static function waitForBarrier(string $barrierPath, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (file_exists($barrierPath)) {
                break;
            }
            usleep(100000); // 100ms
        }
    }

    private static function writeReadyAndWait(string $holdUntilPath, int $timeoutSeconds): void
    {
        // Write a marker that we're holding the transaction
        file_put_contents($holdUntilPath . '.ready', getmypid());

        // Wait until signaled to release
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (file_exists($holdUntilPath)) {
                break;
            }
            usleep(100000);
        }
    }
}

// CLI entry point
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyWorker::run();
}
