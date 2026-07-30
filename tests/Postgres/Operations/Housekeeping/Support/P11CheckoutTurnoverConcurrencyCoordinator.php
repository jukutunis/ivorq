<?php

namespace Tests\Postgres\Operations\Housekeeping\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class P11CheckoutTurnoverConcurrencyCoordinator
{
    private string $basePath;
    private string $workerScript;

    /** @var array<int, array{process: resource, pipes: array<int, resource>, payload_file: string, mode: string}> */
    private array $workers = [];

    /** @var list<string> */
    private array $files = [];

    public function __construct()
    {
        $this->basePath = base_path();
        $this->workerScript = __DIR__ . '/P11CheckoutTurnoverConcurrencyWorker.php';
    }

    public function tempFile(string $prefix): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        @chmod($path, 0600);
        $this->files[] = $path;

        return $path;
    }

    public function spawn(string $mode, array $payload, array $env): int
    {
        $payloadFile = $this->tempFile('p11_payload_');
        file_put_contents($payloadFile, json_encode($payload + ['mode' => $mode], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->workerScript),
            escapeshellarg($this->basePath),
            escapeshellarg($payloadFile),
        );
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, $this->basePath, array_merge(getenv(), $env));

        if (! is_resource($process)) {
            @unlink($payloadFile);
            throw new RuntimeException("Unable to spawn P11 worker {$mode}");
        }

        fclose($pipes[0]);
        $this->workers[] = [
            'process' => $process,
            'pipes' => $pipes,
            'payload_file' => $payloadFile,
            'mode' => $mode,
        ];

        return (int) array_key_last($this->workers);
    }

    public function waitForReady(string $path, int $timeoutSeconds = 10): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $raw = trim((string) file_get_contents($path));
                if ($raw === '') {
                    usleep(50_000);
                    continue;
                }

                return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            }
            usleep(50_000);
        }

        throw new RuntimeException("P11 worker ready marker timed out: {$path}");
    }

    public function release(string $path): void
    {
        file_put_contents($path, 'release');
    }

    public function blockingPids(int $backendPid): array
    {
        $row = DB::selectOne('SELECT pg_blocking_pids(?) AS pids', [$backendPid]);
        $raw = trim((string) ($row->pids ?? ''), '{}');

        if ($raw === '') {
            return [];
        }

        return array_map('intval', explode(',', $raw));
    }

    public function wait(int $index, int $timeoutSeconds = 30): array
    {
        $worker = $this->workers[$index] ?? null;
        if ($worker === null) {
            throw new RuntimeException("Unknown worker {$index}");
        }

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $exited = false;

        while (microtime(true) < $deadline) {
            $read = [$worker['pipes'][1], $worker['pipes'][2]];
            @stream_select($read, $write, $except, 0, 200_000);
            $stdout .= stream_get_contents($worker['pipes'][1]);
            $stderr .= stream_get_contents($worker['pipes'][2]);

            $status = proc_get_status($worker['process']);
            if (! ($status['running'] ?? false)) {
                $stdout .= stream_get_contents($worker['pipes'][1]);
                $stderr .= stream_get_contents($worker['pipes'][2]);
                $exited = true;
                break;
            }
        }

        if (! $exited) {
            @proc_terminate($worker['process'], 9);
            throw new RuntimeException("P11 worker {$worker['mode']} timed out. stderr={$stderr}");
        }

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        @unlink($worker['payload_file']);
        unset($this->workers[$index]);

        $data = json_decode(trim($stdout), true);
        if (! is_array($data)) {
            throw new RuntimeException("P11 worker {$worker['mode']} malformed JSON. exit={$exit} stdout={$stdout} stderr={$stderr}");
        }

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'data' => $data,
        ];
    }

    public function terminateAll(): void
    {
        foreach ($this->workers as $worker) {
            $status = @proc_get_status($worker['process']);
            if ($status['running'] ?? false) {
                @proc_terminate($worker['process'], 9);
            }
            if (is_resource($worker['pipes'][1] ?? null)) {
                @fclose($worker['pipes'][1]);
            }
            if (is_resource($worker['pipes'][2] ?? null)) {
                @fclose($worker['pipes'][2]);
            }
            @proc_close($worker['process']);
            @unlink($worker['payload_file']);
        }
        $this->workers = [];

        foreach ($this->files as $path) {
            @unlink($path);
        }
    }
}
