<?php

namespace Tests\Postgres\Operations\PMS\Support;

class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator
{
    /** @var array<int, array{process: resource, pipes: array, data_file: string, mode: string}> */
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
        $dataFile = tempnam(sys_get_temp_dir(), 'glfe_w_');
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
        $process = proc_open($cmd, $desc, $pipes, null, $env);

        if (!is_resource($process)) {
            @unlink($dataFile);
            throw new \RuntimeException("Failed to spawn worker: {$mode}");
        }

        fclose($pipes[0]);

        $this->workers[] = [
            'process' => $process, 'pipes' => $pipes,
            'data_file' => $dataFile, 'mode' => $mode,
        ];

        return count($this->workers) - 1;
    }

    public function waitForMarker(string $path, int $timeoutS): array
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) {
            if (file_exists($path)) {
                $raw = file_get_contents($path);
                @unlink($path);
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException("Marker at {$path} is not valid JSON: {$raw}");
                }
                return $decoded;
            }
            usleep(100000);
        }
        throw new \RuntimeException("Marker timeout after {$timeoutS}s: {$path}");
    }

    public function isWorkerRunning(int $idx): bool
    {
        if (!isset($this->workers[$idx])) return false;
        $s = proc_get_status($this->workers[$idx]['process']);
        return $s['running'] ?? false;
    }

    public function releaseWorker(string $path): void
    {
        file_put_contents($path, 'release');
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string, data: array}
     */
    private function collectWorker(int $idx, int $timeoutS): array
    {
        $w = $this->workers[$idx];
        $stdout = ''; $stderr = '';
        $deadline = time() + $timeoutS;
        $exited = false;

        while (time() < $deadline) {
            $r = [$w['pipes'][1], $w['pipes'][2]];
            $n = @stream_select($r, $nv, $nv, 1, 0);
            if ($n > 0) {
                $stdout .= stream_get_contents($w['pipes'][1]);
                $stderr .= stream_get_contents($w['pipes'][2]);
            }
            $s = proc_get_status($w['process']);
            if (!$s['running']) {
                $stdout .= stream_get_contents($w['pipes'][1]);
                $stderr .= stream_get_contents($w['pipes'][2]);
                $exited = true;
                break;
            }
        }

        return ['exited' => $exited, 'stdout' => $stdout, 'stderr' => $stderr, 'deadline_expired' => time() >= $deadline];
    }

    public function waitForWorker(int $idx, int $timeoutS): array
    {
        if (!isset($this->workers[$idx])) {
            throw new \InvalidArgumentException("Worker {$idx} not found.");
        }

        $w = $this->workers[$idx];
        $collected = $this->collectWorker($idx, $timeoutS);

        // Close pipes
        @fclose($w['pipes'][1]);
        @fclose($w['pipes'][2]);

        // If still running after deadline, terminate
        if (!$collected['exited']) {
            @proc_terminate($w['process'], 9);
            usleep(100000);
            @proc_close($w['process']);
            @unlink($w['data_file']);
            unset($this->workers[$idx]);
            throw new \RuntimeException(
                "Worker {$idx} ({$w['mode']}) timed out after {$timeoutS}s. Stderr: {$collected['stderr']}"
            );
        }

        // Process exited — get exit code
        $exitCode = proc_close($w['process']);
        @unlink($w['data_file']);
        unset($this->workers[$idx]);

        // Decode stdout JSON
        $stdout = trim($collected['stdout']);
        $data = json_decode($stdout, true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Worker {$idx} ({$w['mode']}) returned malformed JSON. Exit: {$exitCode}. Stdout: {$stdout}. Stderr: {$collected['stderr']}"
            );
        }

        // Non-zero exit = failure — include structured error details
        if ($exitCode !== 0) {
            $msg = "Worker {$idx} ({$w['mode']}) failed with exit {$exitCode}.";
            if (!empty($data['domain_error'])) {
                $msg .= " domain_error: {$data['domain_error']}";
            }
            if (!empty($data['sqlstate'])) {
                $msg .= " sqlstate: {$data['sqlstate']}";
            }
            if (!empty($data['database_message'])) {
                $msg .= " db_msg: {$data['database_message']}";
            }
            if (!empty($data['class'])) {
                $msg .= " class: {$data['class']}";
            }
            if (!empty($data['previous_exception_class'])) {
                $msg .= " prev: {$data['previous_exception_class']}";
            }
            throw new \RuntimeException($msg);
        }

        // Validate required fields
        if (empty($data['php_pid'])) {
            throw new \RuntimeException("Worker {$idx} missing php_pid.");
        }

        return $data;
    }

    public function terminateWorker(int $idx): void
    {
        if (!isset($this->workers[$idx])) return;
        $w = $this->workers[$idx];
        @fclose($w['pipes'][1]);
        @fclose($w['pipes'][2]);
        @proc_terminate($w['process'], 9);
        usleep(100000);
        @proc_close($w['process']);
        @unlink($w['data_file']);
        unset($this->workers[$idx]);
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
