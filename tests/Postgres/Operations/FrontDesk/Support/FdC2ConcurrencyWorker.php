<?php

/**
 * FD-C2 lock-wait concurrency worker.
 *
 * Receives payload via a temporary JSON file (credential-safe — no CLI token).
 * Modes:
 *   - 'lock_hold': acquire FOR UPDATE lock, signal barrier, hold until released, commit
 *   - 'deliver': write pre-lock marker, then call production markDelivered(), report result
 *
 * Output: single JSON line to stdout with at minimum:
 *   php_pid, postgres_backend_pid, postgres_transaction_id, mode,
 *   started_at, completed_at, exit_code
 *
 * For 'deliver' mode on failure: domain_error, database_message
 */

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$basePath = $argv[1] ?? '';
$dataFile = $argv[2] ?? '';

if ($basePath === '' || $dataFile === '' || ! file_exists($dataFile)) {
    fwrite(STDERR, "Usage: php FdC2ConcurrencyWorker.php <basePath> <dataFile>\n");
    exit(2);
}

// Bootstrap Laravel
$app = require $basePath . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Shared\Services\CurrentPropertyService;

$payload = json_decode(file_get_contents($dataFile), true);
if (! is_array($payload)) {
    fwrite(STDERR, "Invalid payload JSON\n");
    exit(2);
}

$mode = $payload['mode'] ?? '';
$phpPid = getmypid();

$startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');

try {
    $pgBackendPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $txId = null; // resolved inside lock_hold after beginTransaction()

    switch ($mode) {
        // ----------------------------------------------------------------
        // lock_hold: Acquire row lock, signal barrier, hold, release
        // ----------------------------------------------------------------
        case 'lock_hold':
            $handoffId = $payload['handoff_id'] ?? '';
            $holdUntilPath = $payload['hold_until_path'] ?? '';
            $lockAcquiredMarker = $payload['lock_acquired_marker'] ?? '';

            if ($handoffId === '' || $holdUntilPath === '' || $lockAcquiredMarker === '') {
                throw new RuntimeException('lock_hold missing required payload fields');
            }

            DB::beginTransaction();

            // Resolve txid AFTER beginTransaction so it identifies the
            // transaction that actually holds the row lock.
            $txId = DB::selectOne('SELECT txid_current() AS txid')->txid;

            $locked = DB::table('front_desk_checkout_housekeeping_handoffs')
                ->where('id', $handoffId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                DB::rollBack();
                throw new RuntimeException('lock_hold: row not found');
            }

            // Signal lock acquired with actual lock-owning transaction evidence
            file_put_contents($lockAcquiredMarker, json_encode([
                'php_pid' => $phpPid,
                'pg_backend_pid' => $pgBackendPid,
                'txid' => $txId,
                'locked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            ]));

            // Wait for release signal
            $deadline = time() + 30;
            while (time() < $deadline) {
                if (file_exists($holdUntilPath)) {
                    @unlink($holdUntilPath);
                    break;
                }
                usleep(100_000);
            }

            DB::commit();

            $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            echo json_encode([
                'php_pid' => $phpPid,
                'postgres_backend_pid' => $pgBackendPid,
                'postgres_transaction_id' => $txId,
                'mode' => $mode,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'exit_code' => 0,
            ]) . "\n";
            exit(0);

        // ----------------------------------------------------------------
        // deliver: Write pre-lock marker, then call production markDelivered()
        // ----------------------------------------------------------------
        case 'deliver':
            $propertyId = $payload['property_id'] ?? '';
            $handoffId = $payload['handoff_id'] ?? '';
            $claimToken = $payload['claim_token'] ?? '';
            $preLockMarker = $payload['pre_lock_marker'] ?? '';

            if ($propertyId === '' || $handoffId === '' || $claimToken === '') {
                throw new RuntimeException('deliver missing required payload fields');
            }

            // Write pre-lock marker BEFORE acquiring any row lock.
            // This lets the coordinator obtain Worker B's PG backend PID
            // and prove blocking via pg_blocking_pids().
            if ($preLockMarker !== '') {
                file_put_contents($preLockMarker, json_encode([
                    'php_pid' => $phpPid,
                    'postgres_backend_pid' => $pgBackendPid,
                    'postgres_transaction_id' => $txId,
                    'marked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                ]));
            }

            $currentProperty = app(CurrentPropertyService::class);
            $currentProperty->setPropertyId($propertyId);

            $deliveryService = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);

            $result = $deliveryService->markDelivered($propertyId, $handoffId, $claimToken);

            $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            echo json_encode([
                'php_pid' => $phpPid,
                'postgres_backend_pid' => $pgBackendPid,
                'postgres_transaction_id' => $txId,
                'mode' => $mode,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'exit_code' => 0,
                'delivered_at' => $result->delivered_at?->toIso8601String(),
            ]) . "\n";
            exit(0);

        default:
            throw new RuntimeException("Unknown mode: {$mode}");
    }
} catch (DomainException $e) {
    $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    echo json_encode([
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid ?? null,
        'postgres_transaction_id' => $txId ?? null,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => $completedAt,
        'exit_code' => 1,
        'domain_error' => $e->getMessage(),
    ]) . "\n";
    exit(1);
} catch (Throwable $e) {
    $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    $previous = $e->getPrevious();
    // Write crash evidence to a debug marker so timeouts don't hide worker failures
    $debugMarker = ($payload['lock_acquired_marker'] ?? '') ?: ($payload['pre_lock_marker'] ?? '');
    if ($debugMarker !== '') {
        @file_put_contents($debugMarker, json_encode([
            'php_pid' => $phpPid,
            'postgres_backend_pid' => $pgBackendPid ?? null,
            'postgres_transaction_id' => $txId ?? null,
            'mode' => $mode,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'exit_code' => 2,
            'exception_class' => get_class($e),
            'database_message' => $e->getMessage(),
            'previous_exception_class' => $previous ? get_class($previous) : null,
        ]));
    }
    fwrite(STDERR, "WORKER_CRASH: " . get_class($e) . ": " . $e->getMessage() . "\n");
    echo json_encode([
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid ?? null,
        'postgres_transaction_id' => $txId ?? null,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => $completedAt,
        'exit_code' => 2,
        'exception_class' => get_class($e),
        'database_message' => $e->getMessage(),
        'previous_exception_class' => $previous ? get_class($previous) : null,
    ]) . "\n";
    exit(2);
}
