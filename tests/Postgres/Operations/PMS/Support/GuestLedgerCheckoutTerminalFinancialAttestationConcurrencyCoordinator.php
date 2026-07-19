<?php

namespace Tests\Postgres\Operations\PMS\Support;

/**
 * Concurrency coordinator for GLF-E terminal financial attestation tests.
 *
 * Coordinates separate PHP worker processes through shared PostgreSQL state.
 * Each worker runs in its own process with its own DB connection.
 */
class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator
{
    private string $phpBinary;
    private string $phpunitXml;
    private string $basePath;

    /** @var array<int, array{process: resource, pipes: array}> */
    private array $workers = [];

    public function __construct()
    {
        $this->phpBinary = PHP_BINARY;
        $this->phpunitXml = base_path('phpunit.pg.xml');
        $this->basePath = base_path();
    }

    /**
     * Spawn a worker process and return its PID.
     *
     * @param array<string, string> $env Extra env vars
     */
    public function spawnWorker(string $workerScript, array $env = []): int
    {
        $envStr = '';
        foreach ($env as $k => $v) {
            $envStr .= escapeshellarg($k) . '=' . escapeshellarg($v) . ' ';
        }

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellcmd($this->phpBinary),
            escapeshellarg($workerScript),
            escapeshellarg($this->basePath),
            escapeshellarg($this->phpunitXml)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            throw new \RuntimeException('Failed to spawn worker process.');
        }

        $this->workers[] = ['process' => $process, 'pipes' => $pipes];

        return count($this->workers) - 1;
    }

    /**
     * Wait for worker to complete and return its stdout.
     */
    public function waitForWorker(int $workerIndex, int $timeoutSeconds = 30): string
    {
        if (! isset($this->workers[$workerIndex])) {
            throw new \InvalidArgumentException("Worker index {$workerIndex} not found.");
        }

        $worker = $this->workers[$workerIndex];
        fclose($worker['pipes'][0]);

        $output = '';
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $r = [$worker['pipes'][1]];
            $w = null; $e = null;
            $changed = stream_select($r, $w, $e, 1, 0);
            if ($changed > 0) {
                $output .= stream_get_contents($worker['pipes'][1]);
            }
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $output .= stream_get_contents($worker['pipes'][1]);
                break;
            }
        }

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        return $output;
    }

    /**
     * Clean up all workers, rolling back any open transactions.
     */
    public function cleanup(): void
    {
        foreach ($this->workers as $worker) {
            if (is_resource($worker['process'])) {
                @fclose($worker['pipes'][0]);
                @fclose($worker['pipes'][1]);
                @fclose($worker['pipes'][2]);
                @proc_terminate($worker['process'], 9);
                @proc_close($worker['process']);
            }
        }
        $this->workers = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
