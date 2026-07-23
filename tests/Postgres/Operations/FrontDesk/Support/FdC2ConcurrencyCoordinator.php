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

    /** @var array<int, array{process: resource, pipes: array, data_file: string, mode: string}> */
    private array $workers = [];

    public function __construct()
    {
        $this->basePath = base_path();
        $this->workerScript = __DIR__ . '/FdC2ConcurrencyWorker.php';
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
        $this->workers[] = ['process' => $proc, 'pipes' => $pipes, 'data_file' => $dataFile, 'mode' => $mode];

        return count($this->workers) - 1;
    }

    /** Wait for a marker file to appear, read and delete it. */
    public function waitForMarker(string $path, int $timeoutS): array
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) {
            if (file_exists($path)) {
                $raw = file_get_contents($path);
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
     * Prove Worker B is blocked behind Worker A via pg_blocking_pids.
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
            $row = DB::selectOne(
                'SELECT pid, state, wait_event_type, wait_event, '
                . 'EXISTS (SELECT 1 FROM unnest(pg_blocking_pids(?)) AS blocker_pid WHERE blocker_pid = ?) AS blocked_by_expected '
                . 'FROM pg_stat_activity WHERE pid = ?',
                [$blockedBackendPid, $expectedBlockerBackendPid, $blockedBackendPid]
            );

            if ($row && ! empty($row->blocked_by_expected)) {
                return true;
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

        if (! $c['exited']) {
            @proc_terminate($w['process'], 9);
            usleep(100_000);
            @proc_close($w['process']);
            @unlink($w['data_file']);
            unset($this->workers[$idx]);
            throw new RuntimeException("Worker {$idx} ({$w['mode']}) timed out. Stderr: {$c['stderr']}");
        }

        $exit = proc_close($w['process']);
        @unlink($w['data_file']);
        unset($this->workers[$idx]);

        $d = json_decode(trim($c['stdout']), true);
        if (! is_array($d) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Worker {$idx} ({$w['mode']}) malformed JSON. Exit:{$exit}. Raw:{$c['stdout']}");
        }

        return ['exit' => $exit, 'data' => $d, 'stderr' => $c['stderr']];
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

        return $r['data'];
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

        return $r['data'];
    }
}
