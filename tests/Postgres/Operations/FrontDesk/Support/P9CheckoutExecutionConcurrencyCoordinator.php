<?php

namespace Tests\Postgres\Operations\FrontDesk\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Package 9 Checkout Execution Concurrency Coordinator.
 *
 * Spawns workers with credential-safe JSON transport,
 * provides barrier/release semantics, and validates
 * pg_blocking_pids proof.
 */
class P9CheckoutExecutionConcurrencyCoordinator
{
    private string $basePath;
    private string $workerScript;

    /** @var array<int, array{process: resource, pipes: array, data_file: string, mode: string}> */
    private array $workers = [];

    /** @var list<string> Temp files to clean up on teardown. */
    private array $cleanupFiles = [];

    public function __construct()
    {
        $this->basePath     = base_path();
        $this->workerScript = __DIR__ . '/P9CheckoutExecutionConcurrencyWorker.php';
    }

    public function __destruct()
    {
        foreach ($this->cleanupFiles as $path) {
            @unlink($path);
        }
        foreach ($this->workers as $w) {
            @unlink($w['data_file']);
        }
    }

    /**
     * Spawn a worker process.
     *
     * @param string $mode  'lock_hold' | 'lock_hold_rollback' | 'execute' | 'execute_blocked'
     * @param array  $payload  Fixture data with marker_dir, database, stay IDs, etc.
     * @return int  worker index
     */
    public function spawnWorker(string $mode, array $payload): int
    {
        $dataFile = tempnam(sys_get_temp_dir(), 'p9_w_');
        @chmod($dataFile, 0600);
        file_put_contents($dataFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->workerScript),
            escapeshellarg($dataFile),
            escapeshellarg($mode)
        );

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, null, getenv());

        if (!is_resource($proc)) {
            @unlink($dataFile);
            throw new RuntimeException("P9 spawnWorker failed: {$mode}");
        }

        fclose($pipes[0]);
        $this->workers[] = [
            'process'   => $proc,
            'pipes'     => $pipes,
            'data_file' => $dataFile,
            'mode'      => $mode,
        ];

        return count($this->workers) - 1;
    }

    /**
     * Wait for a marker file to appear with valid JSON content, read and delete it.
     */
    public function waitForMarker(string $path, int $timeoutS = 30): array
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) {
            if (file_exists($path) && filesize($path) > 0) {
                $raw = file_get_contents($path);
                if ($raw === false || trim($raw) === '') {
                    usleep(50_000);
                    continue;
                }
                @unlink($path);
                $d = json_decode($raw, true);
                if (!is_array($d)) {
                    throw new RuntimeException("P9 Marker not valid JSON: {$raw}");
                }
                return $d;
            }
            usleep(100_000);
        }
        throw new RuntimeException("P9 Marker timeout after {$timeoutS}s: {$path}");
    }

    /** Get worker stdout output. */
    public function getWorkerOutput(int $idx): string
    {
        if (!isset($this->workers[$idx])) {
            return '';
        }
        $out = '';
        while ($line = fgets($this->workers[$idx]['pipes'][1])) {
            $out .= $line;
        }
        return $out;
    }

    /**
     * Wait for a worker to exit and return structured stdout, stderr, and exit-code evidence.
     *
     * @return array{mode: string, exit_code: int, stdout: string, stderr: string, payload: array<string, mixed>}
     */
    public function waitForWorkerResult(int $idx, int $timeoutS = 30): array
    {
        if (!isset($this->workers[$idx])) {
            throw new RuntimeException("P9 worker {$idx} is not registered.");
        }

        $worker = $this->workers[$idx];
        $deadline = microtime(true) + $timeoutS;
        $exitCode = -1;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($worker['process']);
            if (!($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }
            usleep(100_000);
        }

        if ($exitCode === -1) {
            throw new RuntimeException("P9 worker {$idx} ({$worker['mode']}) did not exit within {$timeoutS}s.");
        }

        $stdout = is_resource($worker['pipes'][1] ?? null) ? (string) stream_get_contents($worker['pipes'][1]) : '';
        $stderr = is_resource($worker['pipes'][2] ?? null) ? (string) stream_get_contents($worker['pipes'][2]) : '';

        foreach ([0, 1, 2] as $pipe) {
            if (is_resource($worker['pipes'][$pipe] ?? null)) {
                @fclose($worker['pipes'][$pipe]);
            }
        }

        @proc_close($worker['process']);
        @unlink($worker['data_file']);
        unset($this->workers[$idx]);

        $payload = json_decode(trim($stdout), true);
        if (!is_array($payload)) {
            throw new RuntimeException("P9 worker {$idx} ({$worker['mode']}) did not return valid JSON stdout: {$stdout}; stderr: {$stderr}");
        }

        return [
            'mode' => $worker['mode'],
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'payload' => $payload,
        ];
    }

    /** Check if worker is still running. */
    public function isWorkerRunning(int $idx): bool
    {
        if (!isset($this->workers[$idx])) {
            return false;
        }
        $status = proc_get_status($this->workers[$idx]['process']);
        return $status['running'] ?? false;
    }

    /** Write a release signal to a path. */
    public function releaseWorker(string $path): void
    {
        file_put_contents($path, 'release');
    }

    /** Track a file for cleanup. */
    public function trackCleanup(string $path): void
    {
        $this->cleanupFiles[] = $path;
    }

    /**
     * Prove Worker B is blocked behind Worker A via pg_blocking_pids.
     */
    public function proveBlocking(int $blockedBackendPid, int $expectedBlockerBackendPid, int $timeoutS = 15): bool
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) {
            $row = DB::connection('pgsql_concurrency')->selectOne(
                'SELECT pg_blocking_pids(pid) AS blocking_pids, wait_event_type, wait_event, state
                   FROM pg_stat_activity
                  WHERE pid = ?',
                [$blockedBackendPid]
            );

            if (!$row) {
                usleep(200_000);
                continue;
            }

            $blockingPids = $this->parsePgIntArray($row->blocking_pids ?? '{}');

            if (in_array($expectedBlockerBackendPid, $blockingPids, true)) {
                return true;
            }

            // If worker B is in an active transaction with 'Lock' wait_event, blocking might be via relation lock
            if ($row->wait_event_type === 'Lock' && $row->state === 'active') {
                // Retry a few more times for pg_blocking_pids to populate
                usleep(500_000);
                continue;
            }

            usleep(200_000);
        }
        return false;
    }

    /**
     * Return current pg_blocking_pids evidence for a backend PID.
     *
     * @return list<int>
     */
    public function blockingPidsFor(int $backendPid): array
    {
        $row = DB::connection('pgsql_concurrency')->selectOne(
            'SELECT pg_blocking_pids(pid) AS blocking_pids
               FROM pg_stat_activity
              WHERE pid = ?',
            [$backendPid]
        );

        if (! $row) {
            return [];
        }

        return $this->parsePgIntArray($row->blocking_pids ?? '{}');
    }

    public function proveNoBlockingBetween(int $backendPidA, int $backendPidB, int $samples = 5): bool
    {
        for ($i = 0; $i < $samples; $i++) {
            $aBlockedBy = $this->blockingPidsFor($backendPidA);
            $bBlockedBy = $this->blockingPidsFor($backendPidB);

            if (in_array($backendPidB, $aBlockedBy, true) || in_array($backendPidA, $bBlockedBy, true)) {
                return false;
            }

            usleep(200_000);
        }

        return true;
    }

    /**
     * Get backend PID evidence for a worker via its marker data.
     */
    public function getBackendPidFromMarker(string $markerPath, int $timeoutS = 15): int
    {
        $data = $this->waitForMarker($markerPath, $timeoutS);
        return (int) ($data['backend_pid'] ?? 0);
    }

    /**
     * Terminate all workers and clean up.
     */
    public function terminateAllWorkers(int $graceMs = 500): array
    {
        $report = [];
        foreach ($this->workers as $idx => $w) {
            $entry = [
                'mode'                  => $w['mode'],
                'process_handle_closed' => false,
                'payload_file_deleted'  => false,
                'force_terminated'      => false,
            ];

            // Close stdin
            if (is_resource($w['pipes'][0] ?? null)) {
                @fclose($w['pipes'][0]);
            }

            usleep($graceMs * 1000);

            $status    = @proc_get_status($w['process']);
            $wasRunning = $status['running'] ?? false;
            if ($wasRunning) {
                @proc_terminate($w['process'], 9);
                usleep(100_000);
                $entry['force_terminated'] = true;
            }

            if (is_resource($w['pipes'][1] ?? null)) {
                @fclose($w['pipes'][1]);
            }
            if (is_resource($w['pipes'][2] ?? null)) {
                @fclose($w['pipes'][2]);
            }

            @proc_close($w['process']);
            $entry['process_handle_closed'] = true;

            @unlink($w['data_file']);
            clearstatcache(true, $w['data_file']);
            $entry['payload_file_deleted'] = !file_exists($w['data_file']);

            $report[] = $entry;
        }
        $this->workers = [];
        return $report;
    }

    /**
     * Parse a PostgreSQL integer array literal like '{123,456}' into a PHP array.
     */
    private function parsePgIntArray(string $pgArray): array
    {
        $pgArray = trim($pgArray, '{}');
        if ($pgArray === '') {
            return [];
        }
        return array_map('intval', explode(',', $pgArray));
    }
}
