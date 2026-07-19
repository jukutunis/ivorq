<?php

namespace Tests\Postgres\Operations\PMS\Support;

class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator
{
    /** @var array<int, array{process: resource, pipes: array}> */
    private array $workers = [];

    private string $basePath;
    private string $workerScript;

    public function __construct()
    {
        $this->basePath = base_path();
        $this->workerScript = __DIR__ . '/GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyWorker.php';
    }

    public function spawnWorker(string $mode, array $payload, array $environment = []): int
    {
        $dataFile = tempnam(sys_get_temp_dir(), 'glfe_worker_');
        file_put_contents($dataFile, json_encode(array_merge($payload, ['mode' => $mode]), JSON_UNESCAPED_SLASHES));

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->workerScript),
            escapeshellarg($this->basePath),
            escapeshellarg($dataFile)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv(), $environment);
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, $env);

        if (! is_resource($process)) {
            unlink($dataFile);
            throw new \RuntimeException('Failed to spawn worker process.');
        }

        fclose($pipes[0]);

        $this->workers[] = [
            'process' => $process,
            'pipes' => $pipes,
            'data_file' => $dataFile,
            'mode' => $mode,
        ];

        return count($this->workers) - 1;
    }

    public function waitForMarker(string $markerPath, int $timeoutSeconds): array
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (file_exists($markerPath)) {
                $content = file_get_contents($markerPath);
                @unlink($markerPath);
                $decoded = json_decode($content, true);
                return is_array($decoded) ? $decoded : ['raw' => $content];
            }
            usleep(100000);
        }
        throw new \RuntimeException("Marker timeout after {$timeoutSeconds}s: {$markerPath}");
    }

    public function isWorkerRunning(int $workerIndex): bool
    {
        if (! isset($this->workers[$workerIndex])) {
            return false;
        }
        $status = proc_get_status($this->workers[$workerIndex]['process']);
        return $status['running'] ?? false;
    }

    public function releaseWorker(string $releasePath): void
    {
        file_put_contents($releasePath, 'release');
    }

    public function waitForWorker(int $workerIndex, int $timeoutSeconds): array
    {
        if (! isset($this->workers[$workerIndex])) {
            throw new \InvalidArgumentException("Worker {$workerIndex} not found.");
        }

        $worker = $this->workers[$workerIndex];
        $stdout = '';
        $stderr = '';
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $r = [$worker['pipes'][1], $worker['pipes'][2]];
            $w = null; $e = null;
            $changed = @stream_select($r, $w, $e, 1, 0);
            if ($changed > 0) {
                $stdout .= stream_get_contents($worker['pipes'][1]);
                $stderr .= stream_get_contents($worker['pipes'][2]);
            }
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $stdout .= stream_get_contents($worker['pipes'][1]);
                $stderr .= stream_get_contents($worker['pipes'][2]);
                break;
            }
        }

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);

        if (file_exists($worker['data_file'])) {
            unlink($worker['data_file']);
        }

        $exitCode = proc_close($worker['process']);
        unset($this->workers[$workerIndex]);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Worker {$workerIndex} exited with code {$exitCode}: {$stderr}");
        }

        $result = json_decode(trim($stdout), true);
        if (! is_array($result) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Worker {$workerIndex} returned malformed JSON: {$stdout}");
        }

        return $result;
    }

    public function terminateWorker(int $workerIndex): void
    {
        if (! isset($this->workers[$workerIndex])) {
            return;
        }
        @proc_terminate($this->workers[$workerIndex]['process'], 9);
        @fclose($this->workers[$workerIndex]['pipes'][1]);
        @fclose($this->workers[$workerIndex]['pipes'][2]);
        @proc_close($this->workers[$workerIndex]['process']);
        if (file_exists($this->workers[$workerIndex]['data_file'])) {
            unlink($this->workers[$workerIndex]['data_file']);
        }
        unset($this->workers[$workerIndex]);
    }

    public function cleanup(): void
    {
        foreach (array_keys($this->workers) as $i) {
            $this->terminateWorker($i);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
