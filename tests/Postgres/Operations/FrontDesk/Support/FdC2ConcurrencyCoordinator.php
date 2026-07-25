<?php

namespace Tests\Postgres\Operations\FrontDesk\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FD-C2 lock-wait concurrency coordinator.
 *
 * Spawns workers with credential-safe JSON transport,
 * provides barrier/release semantics, and validates
 * pg_blocking_pids proof.
 */
class FdC2ConcurrencyCoordinator
{
    private string $basePath;
    private string $workerScript;

    /** @var array<int, array{process: resource, pipes: array, data_file: string, mode: string, hold_until_path: string|null}> */
    private array $workers = [];

    /** @var list<string> Temp marker files to clean up on teardown. */
    private array $markerFiles = [];

    public function __construct()
    {
        $this->basePath = base_path();
        $this->workerScript = __DIR__ . '/FdC2ConcurrencyWorker.php';
    }

    public function __destruct()
    {
        foreach ($this->markerFiles as $path) {
            @unlink($path);
        }
        foreach ($this->workers as $w) {
            @unlink($w['data_file']);
        }
    }

    /**
     * Spawn a worker process.
     *
     * @param string $mode 'lock_hold' | 'deliver'
     * @param array $payload mode-specific data (handoff_id, property_id, claim_token, etc.)
     * @param array $environment extra env vars
     * @return int worker index
     */
    public function spawnWorker(string $mode, array $payload, array $environment = []): int
    {
        $dataFile = tempnam(sys_get_temp_dir(), 'fdc2_w_');
        // Restrictive permissions: owner read/write only
        @chmod($dataFile, 0600);
        file_put_contents($dataFile, json_encode(array_merge($payload, ['mode' => $mode]), JSON_UNESCAPED_SLASHES));

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->workerScript),
            escapeshellarg($this->basePath),
            escapeshellarg($dataFile)
        );

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(getenv(), $environment);
        $proc = proc_open($cmd, $desc, $pipes, null, $env);

        if (! is_resource($proc)) {
            @unlink($dataFile);
            throw new RuntimeException("spawnWorker failed: {$mode}");
        }

        fclose($pipes[0]);
        $holdUntilPath = $payload['hold_until_path'] ?? null;
        $this->workers[] = ['process' => $proc, 'pipes' => $pipes, 'data_file' => $dataFile, 'mode' => $mode, 'hold_until_path' => $holdUntilPath];

        return count($this->workers) - 1;
    }

    /** Wait for a marker file to appear with valid JSON content, read and delete it. */
    public function waitForMarker(string $path, int $timeoutS): array
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) {
            if (file_exists($path)) {
                $raw = file_get_contents($path);
                // Wait for non-empty content — tempnam may create an empty file
                // before the worker writes to it.
                if ($raw === false || trim($raw) === '') {
                    usleep(50_000);
                    continue;
                }
                @unlink($path);
                $d = json_decode($raw, true);
                if (! is_array($d)) {
                    throw new RuntimeException("Marker not valid JSON: {$raw}");
                }
                return $d;
            }
            usleep(100_000);
        }
        throw new RuntimeException("Marker timeout after {$timeoutS}s: {$path}");
    }

    /** Check if worker is still running. */
    public function isWorkerRunning(int $idx): bool
    {
        return isset($this->workers[$idx]) && (proc_get_status($this->workers[$idx]['process'])['running'] ?? false);
    }

    /** Write release signal to a path. */
    public function releaseWorker(string $path): void
    {
        file_put_contents($path, 'release');
    }

    /**
     * Track an external marker file for guaranteed cleanup on teardown.
     */
    public function trackMarker(string $path): void
    {
        $this->markerFiles[] = $path;
    }

    /**
     * Terminate all registered workers, close pipes, delete payload files,
     * and remove them from the registry. Safe to call at any point in the
     * worker lifecycle (before start, while running, while blocked, after
     * completion, after timeout).
     *
     * @param int $graceMilliseconds wait before force-kill
     */
    /**
     * Terminate all registered workers, close pipes, delete payload files,
     * and remove them from the registry. Returns a cleanup report for each
     * worker containing mode, process_handle_closed, payload_file_deleted,
     * release_sent, and force_terminated.
     *
     * @param int $graceMilliseconds wait before force-kill
     * @return list<array<string, mixed>>
     */
    public function terminateAllWorkers(int $graceMilliseconds = 500): array
    {
        $report = [];

        foreach ($this->workers as $idx => $w) {
            $entry = [
                'mode'                  => $w['mode'],
                'process_handle_closed' => false,
                'payload_file_deleted'  => false,
                'payload_file_path'     => $w['data_file'],
                'release_sent'          => false,
                'force_terminated'      => false,
            ];

            // Send release signal if this is a lock_hold worker
            $holdPath = $w['hold_until_path'] ?? null;
            if ($holdPath !== null && $w['mode'] === 'lock_hold') {
                $releaseBytes = @file_put_contents($holdPath, 'release');
                $entry['release_sent'] = ($releaseBytes !== false);
            }

            // Close stdin
            if (is_resource($w['pipes'][0] ?? null)) {
                @fclose($w['pipes'][0]);
            }

            // Grace period
            usleep($graceMilliseconds * 1000);

            // Force-terminate if still running
            $status = @proc_get_status($w['process']);
            $wasRunning = $status['running'] ?? false;
            if ($wasRunning) {
                @proc_terminate($w['process'], 9);
                usleep(100_000);
                $entry['force_terminated'] = true;
            }

            // Close stdout/stderr
            if (is_resource($w['pipes'][1] ?? null)) {
                @fclose($w['pipes'][1]);
            }
            if (is_resource($w['pipes'][2] ?? null)) {
                @fclose($w['pipes'][2]);
            }

            // Close process handle
            $closeResult = @proc_close($w['process']);
            $entry['process_handle_closed'] = ! is_resource($w['process']);

            // Delete payload JSON file
            @unlink($w['data_file']);
            clearstatcache(true, $w['data_file']);
            $entry['payload_file_deleted'] = ! file_exists($w['data_file']);

            $report[] = $entry;
        }
        $this->workers = [];
        return $report;
    }

    /**
     * Query PostgreSQL for Worker B's active transaction evidence
     * using its backend PID. Returns associative array with:
     *   - backend_xid (the transaction ID if in a transaction)
     *   - xact_start (when the current transaction started)
     *   - state (active/idle/etc.)
     *   - wait_event_type, wait_event (what it's waiting for)
     *   - blocking_pids (PG array of blocking backend PIDs)
     *
     * @param int $backendPid Worker B's PostgreSQL backend PID
     * @return array|null null if session not found
     */
    public function getBlockedTransactionEvidence(int $backendPid): ?array
    {
        $row = DB::selectOne(
            'SELECT backend_xid, xact_start, state, wait_event_type, wait_event,
                    pg_blocking_pids(pid) AS blocking_pids
               FROM pg_stat_activity
              WHERE pid = ?',
            [$backendPid]
        );

        if (! $row) {
            return null;
        }

        return [
            'backend_xid'      => $row->backend_xid,
            'xact_start'       => $row->xact_start,
            'state'            => $row->state,
            'wait_event_type'  => $row->wait_event_type,
            'wait_event'       => $row->wait_event,
            'blocking_pids'    => $row->blocking_pids,
        ];
    }

    /**
     * Query pg_locks for the virtual transaction IDs of two backends.
     * Returns ['worker_a_vxid' => ..., 'worker_b_vxid' => ...] or nulls
     * if not found.
     *
     * @param int $pidA Worker A backend PID
     * @param int $pidB Worker B backend PID
     * @return array{worker_a_vxid: string|null, worker_b_vxid: string|null}
     */
    public function getVirtualTransactionIds(int $pidA, int $pidB): array
    {
        $rowA = DB::selectOne(
            'SELECT DISTINCT virtualtransaction AS vxid FROM pg_locks WHERE pid = ?',
            [$pidA]
        );
        $rowB = DB::selectOne(
            'SELECT DISTINCT virtualtransaction AS vxid FROM pg_locks WHERE pid = ?',
            [$pidB]
        );

        return [
            'worker_a_vxid' => $rowA->vxid ?? null,
            'worker_b_vxid' => $rowB->vxid ?? null,
        ];
    }

    /**
     * Prove Worker B is blocked behind Worker A via pg_blocking_pids.
     *
     * @param int $blockedBackendPid Worker B's PostgreSQL backend PID
     * @param int $expectedBlockerBackendPid Worker A's PostgreSQL backend PID
     * @param int $timeoutS max wait for blocking to appear
     * @return bool true if blocking confirmed
     */
    /**
     * Prove Worker B is blocked behind Worker A via PostgreSQL lock monitoring.
     *
     * Uses pg_blocking_pids() to verify Worker B is waiting for a lock
     * held by Worker A.
     *
     * @param int $blockedBackendPid Worker B's PostgreSQL backend PID
     * @param int $expectedBlockerBackendPid Worker A's PostgreSQL backend PID
     * @param int $timeoutS max wait for blocking to appear
     * @return bool true if blocking confirmed
     */
    public function proveBlockedBy(int $blockedBackendPid, int $expectedBlockerBackendPid, int $timeoutS = 15): bool
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) {
            // pg_blocking_pids returns an array of PIDs blocking the given session.
            $row = DB::selectOne(
                'SELECT pg_blocking_pids(?) AS pids',
                [$blockedBackendPid]
            );

            if ($row && ! empty($row->pids) && $row->pids !== '{}') {
                $pids = trim($row->pids, '{}');
                $pidArray = array_map('intval', explode(',', $pids));
                if (in_array($expectedBlockerBackendPid, $pidArray, true)) {
                    return true;
                }
            }
            usleep(100_000);
        }
        return false;
    }

    /** Collect worker output. */
    private function collect(int $idx, int $timeoutS): array
    {
        $w = $this->workers[$idx];
        $out = '';
        $err = '';
        $exited = false;
        $dl = time() + $timeoutS;

        while (time() < $dl) {
            $r = [$w['pipes'][1], $w['pipes'][2]];
            @stream_select($r, $nv, $nv, 1, 0);
            $out .= stream_get_contents($w['pipes'][1]);
            $err .= stream_get_contents($w['pipes'][2]);
            $s = proc_get_status($w['process']);
            if (! $s['running']) {
                $out .= stream_get_contents($w['pipes'][1]);
                $err .= stream_get_contents($w['pipes'][2]);
                $exited = true;
                break;
            }
        }

        return ['exited' => $exited, 'stdout' => $out, 'stderr' => $err, 'timed_out' => time() >= $dl];
    }

    /** Decode and validate worker output. */
    private function decodeAndValidate(int $idx): array
    {
        $w = $this->workers[$idx];
        $c = $this->collect($idx, 30);
        @fclose($w['pipes'][1]);
        @fclose($w['pipes'][2]);

        // Always terminate before closing to ensure the process is dead
        $status = proc_get_status($w['process']);
        $wasRunningBeforeClose = $status['running'];
        if ($wasRunningBeforeClose) {
            @proc_terminate($w['process'], 9);
            usleep(100_000);
        }

        if (! $c['exited']) {
            @proc_close($w['process']);
            @unlink($w['data_file']);
            unset($this->workers[$idx]);
            throw new RuntimeException("Worker {$idx} ({$w['mode']}) timed out. Stderr: {$c['stderr']}");
        }

        $exit = proc_close($w['process']);
        @unlink($w['data_file']);
        unset($this->workers[$idx]);

        // Process termination evidence: proc_close() returned an exit code
        // (waited for process to finish), and proc_terminate was called if
        // the process hadn't already exited.
        $processTerminated = ($exit !== -1) || (isset($wasRunningBeforeClose) && ! $wasRunningBeforeClose);

        $d = json_decode(trim($c['stdout']), true);
        if (! is_array($d) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Worker {$idx} ({$w['mode']}) malformed JSON. Exit:{$exit}. Raw:{$c['stdout']}");
        }

        return ['exit' => $exit, 'data' => $d, 'stderr' => $c['stderr'], 'process_terminated' => $processTerminated];
    }

    /**
     * Wait for a worker expected to succeed (exit 0).
     */
    public function waitForWorker(int $idx, int $timeoutS): array
    {
        $r = $this->decodeAndValidate($idx);
        $required = ['php_pid', 'mode'];
        foreach ($required as $f) {
            if (empty($r['data'][$f])) {
                throw new RuntimeException("Worker {$idx} missing {$f}");
            }
        }

        if ($r['exit'] !== 0) {
            $msg = "Worker {$idx} ({$r['data']['mode']}) failed exit:{$r['exit']}.";
            foreach (['domain_error', 'sqlstate', 'database_message'] as $f) {
                if (! empty($r['data'][$f])) {
                    $msg .= " {$f}:{$r['data'][$f]}";
                }
            }
            throw new RuntimeException($msg);
        }

        return array_merge($r['data'], ['coordinator_process_terminated' => $r['process_terminated']]);
    }

    /**
     * Wait for a worker expected to fail with a controlled FD-C2 domain exception.
     */
    public function waitForRejectedWorker(int $idx, string $expectedDomainError, int $timeoutS): array
    {
        $r = $this->decodeAndValidate($idx);
        $required = ['php_pid', 'mode'];
        foreach ($required as $f) {
            if (empty($r['data'][$f])) {
                throw new RuntimeException("Worker {$idx} missing {$f}");
            }
        }

        if ($r['exit'] === 0) {
            throw new RuntimeException("Worker {$idx} ({$r['data']['mode']}) succeeded unexpectedly.");
        }

        $actual = $r['data']['domain_error'] ?? '';
        if ($actual !== $expectedDomainError) {
            throw new RuntimeException("Worker {$idx} expected {$expectedDomainError} but got {$actual}. Exit:{$r['exit']}. Msg:{$r['data']['database_message']}");
        }

        return array_merge($r['data'], ['coordinator_process_terminated' => $r['process_terminated']]);
    }
}
