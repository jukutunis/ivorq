<?php

namespace Tests\Postgres\Operations\PMS\Support;

use Illuminate\Support\Str;

/**
 * GLF-D Concurrency Coordinator.
 *
 * Manages disposable database lifecycle, worker spawning, synchronization
 * barriers, and result collection for real concurrency proof scenarios.
 */
class GuestLedgerSettlementReadinessConcurrencyCoordinator
{
    private const WORKER_DEADLINE_SECONDS = 180.0;
    private const TERMINATION_GRACE_SECONDS = 3.0;
    private const POLL_MICROSECONDS = 100000;

    private string $dbName;
    private string $baseDb;
    private string $dbHost;
    private string $dbPort;
    private string $dbUser;
    private string $dbPass;
    private string $resultDir;

    /** @var array<string, array{proc: resource, scenario: string}> */
    private array $procs = [];

    public function __construct()
    {
        $this->dbName = 'ivorq_concurrency_glf_d_' . Str::lower(Str::random(8));
        $this->baseDb = env('DB_DATABASE', 'ivorq_testing');
        $this->dbHost = env('DB_HOST', '127.0.0.1');
        $this->dbPort = env('DB_PORT', '5432');
        $this->dbUser = env('DB_USERNAME', 'postgres');
        $this->dbPass = env('DB_PASSWORD', '');
        $this->resultDir = sys_get_temp_dir() . '/glf-d-conc-' . Str::random(8);
    }

    public function dbName(): string
    {
        return $this->dbName;
    }

    public function resultDir(): string
    {
        return $this->resultDir;
    }

    public function setUpDisposableDb(): void
    {
        $pdo = $this->adminPdo($this->baseDb);
        $pdo->exec('DROP DATABASE IF EXISTS "' . $this->dbName . '"');
        $pdo->exec('CREATE DATABASE "' . $this->dbName . '" TEMPLATE template0 ENCODING UTF8');
        $pdo = null;
    }

    public function tearDownDisposableDb(): void
    {
        $this->terminateWorkers();
        $this->waitForWorkers(self::TERMINATION_GRACE_SECONDS);
        $this->closeWorkers();

        try {
            $pdo = $this->adminPdo($this->baseDb);
            $pdo->exec('DROP DATABASE IF EXISTS "' . $this->dbName . '"');
        } catch (\Throwable) {
            // Best effort.
        }

        if (is_dir($this->resultDir)) {
            @array_map('unlink', glob($this->resultDir . '/*') ?: []);
            @rmdir($this->resultDir);
        }
    }

    /**
     * @param array<int, array<string, string>> $extra
     * @return array<int, array<string, mixed>>
     */
    public function spawnWorkers(int $count, string $scenario, array $extra = []): array
    {
        @mkdir($this->resultDir, 0700, true);
        $barrier = $this->resultDir . '/barrier';
        $hasMutatorPeer = collect($extra)
            ->contains(fn (array $workerArgs): bool => (string) ($workerArgs['IVORQ_MUTATOR'] ?? '') !== '');

        try {
            for ($i = 0; $i < $count; $i++) {
                $workerId = "w{$i}";
                $resultFile = $this->resultDir . "/result-{$workerId}.json";
                $stderrFile = $this->resultDir . "/stderr-{$workerId}.txt";
                $argsFile = $this->resultDir . "/args-{$workerId}.json";

                $cmdArgs = array_merge([
                    'IVORQ_WORKER_ID' => $workerId,
                    'IVORQ_SCENARIO' => $scenario,
                    'IVORQ_RESULT_FILE' => $resultFile,
                    'IVORQ_BARRIER' => $barrier,
                    'IVORQ_WORKER_INDEX' => (string) $i,
                    'IVORQ_EXPECTED_WORKERS' => (string) $count,
                    'IVORQ_HAS_MUTATOR_PEER' => $hasMutatorPeer ? '1' : '0',
                ], $extra[$i] ?? []);

                file_put_contents($argsFile, json_encode($cmdArgs));

                $workerScript = __DIR__ . '/GuestLedgerSettlementReadinessConcurrencyWorker.php';
                $cmd = sprintf(
                    '%s %s %s',
                    PHP_BINARY,
                    escapeshellarg($workerScript),
                    escapeshellarg($argsFile)
                );

                $processEnv = array_merge(getenv(), [
                    'DB_DATABASE' => $this->dbName,
                    'APP_ENV' => 'testing',
                ]);

                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $proc = proc_open($cmd, $spec, $pipes, null, $processEnv);
                if (! is_resource($proc)) {
                    $this->terminateWorkers();
                    $this->closeWorkers();

                    return [[
                        '_proc_error' => 'worker_launch_failed',
                        'worker_id' => $workerId,
                        'scenario' => $scenario,
                        'result_dir' => $this->resultDir,
                        'known_barrier_files' => $this->knownBarrierFiles(),
                        '_stderr' => file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '',
                    ]];
                }

                fclose($pipes[0]);
                $this->procs[$workerId] = ['proc' => $proc, 'scenario' => $scenario];
            }

            if (count($this->procs) !== $count) {
                $this->terminateWorkers();

                return [[
                    '_proc_error' => 'worker_count_mismatch',
                    'scenario' => $scenario,
                    'expected_workers' => $count,
                    'launched_workers' => count($this->procs),
                    'result_dir' => $this->resultDir,
                    'known_barrier_files' => $this->knownBarrierFiles(),
                ]];
            }

            if (! $this->waitForWorkers(self::WORKER_DEADLINE_SECONDS)) {
                $results = $this->collectResults($scenario, true);
                $this->terminateWorkers();
                $this->waitForWorkers(self::TERMINATION_GRACE_SECONDS);

                return $results;
            }

            return $this->collectResults($scenario, false);
        } finally {
            $this->closeWorkers();
        }
    }

    private function waitForWorkers(float $deadlineSeconds): bool
    {
        $started = microtime(true);
        while ((microtime(true) - $started) < $deadlineSeconds) {
            $allExited = true;
            foreach ($this->procs as $pdata) {
                $status = proc_get_status($pdata['proc']);
                if (($status['running'] ?? false) === true) {
                    $allExited = false;
                    break;
                }
            }

            if ($allExited) {
                return true;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectResults(string $scenario, bool $timedOut): array
    {
        $results = [];

        foreach ($this->procs as $workerId => $pdata) {
            $resultFile = $this->resultDir . "/result-{$workerId}.json";
            $stderrFile = $this->resultDir . "/stderr-{$workerId}.txt";
            $status = proc_get_status($pdata['proc']);
            $isRunning = ($status['running'] ?? false) === true;

            if ($timedOut && $isRunning) {
                $results[] = [
                    '_proc_error' => 'worker_timeout',
                    'worker_id' => $workerId,
                    'scenario' => $scenario,
                    'result_dir' => $this->resultDir,
                    'known_barrier_files' => $this->knownBarrierFiles(),
                    '_stderr' => file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '',
                    'process_status' => $this->safeProcessStatus($status),
                ];
                continue;
            }

            if (file_exists($resultFile)) {
                $decoded = json_decode(file_get_contents($resultFile), true);
                if (! is_array($decoded)) {
                    $decoded = [
                        '_parse_error' => 'malformed_json',
                        '_stderr' => file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '',
                    ];
                }
            } else {
                $decoded = [
                    '_proc_error' => 'no_result_file',
                    '_stderr' => file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '',
                ];
            }

            $decoded['_exit_code'] = $status['exitcode'] ?? null;
            if (isset($decoded['error']) && ! isset($decoded['_stderr'])) {
                $decoded['_stderr'] = file_exists($stderrFile) ? trim(file_get_contents($stderrFile)) : '';
            }
            $results[] = $decoded;
        }

        return $results;
    }

    private function terminateWorkers(): void
    {
        foreach ($this->procs as $pdata) {
            $status = proc_get_status($pdata['proc']);
            if (($status['running'] ?? false) === true) {
                @proc_terminate($pdata['proc']);
            }
        }
    }

    private function closeWorkers(): void
    {
        foreach ($this->procs as $pdata) {
            $status = proc_get_status($pdata['proc']);
            if (($status['running'] ?? false) === true) {
                @proc_terminate($pdata['proc']);
            }
            @proc_close($pdata['proc']);
        }

        $this->procs = [];
    }

    /**
     * @return string[]
     */
    private function knownBarrierFiles(): array
    {
        $files = glob($this->resultDir . '/barrier*') ?: [];
        sort($files);

        return array_map('basename', $files);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function safeProcessStatus(array $status): array
    {
        unset($status['command']);

        return $status;
    }

    private function adminPdo(string $dbName): \PDO
    {
        return new \PDO(
            "pgsql:host={$this->dbHost};port={$this->dbPort};dbname={$dbName}",
            $this->dbUser,
            $this->dbPass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    private function execWithEnv(string $cmd, array $env): void
    {
        $descriptorspec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
        if (is_resource($process)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
