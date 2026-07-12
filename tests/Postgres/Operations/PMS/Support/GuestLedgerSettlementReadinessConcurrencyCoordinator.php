<?php

namespace Tests\Postgres\Operations\PMS\Support;

use Illuminate\Support\Str;

/**
 * GLF-D Concurrency Coordinator.
 *
 * Manages disposable database lifecycle, worker spawning, synchronization
 * barriers, and result collection for real concurrency proof scenarios.
 *
 * The disposable database is created, migrated, used by workers, then
 * dropped in finally. Never touches ivorq_testing.
 */
class GuestLedgerSettlementReadinessConcurrencyCoordinator
{
    private string $dbName;
    private string $baseDb;
    private string $dbHost;
    private string $dbPort;
    private string $dbUser;
    private string $dbPass;
    private string $resultDir;

    /** @var array<string, array{proc: resource, pipes: array}> */
    private array $procs = [];

    public function __construct()
    {
        $this->dbName  = 'ivorq_concurrency_glf_d_' . Str::lower(Str::random(8));
        $this->baseDb  = env('DB_DATABASE', 'ivorq_testing');
        $this->dbHost  = env('DB_HOST', '127.0.0.1');
        $this->dbPort  = env('DB_PORT', '5432');
        $this->dbUser  = env('DB_USERNAME', 'postgres');
        $this->dbPass  = env('DB_PASSWORD', '');
        $this->resultDir = sys_get_temp_dir() . '/glf-d-conc-' . Str::random(8);
    }

    public function dbName(): string { return $this->dbName; }
    public function resultDir(): string { return $this->resultDir; }

    /**
     * Create and migrate the disposable database.
     */
    public function setUpDisposableDb(): void
    {
        $pdo = $this->adminPdo($this->baseDb);
        $pdo->exec('DROP DATABASE IF EXISTS "' . $this->dbName . '"');
        $pdo->exec('CREATE DATABASE "' . $this->dbName . '" TEMPLATE template0 ENCODING UTF8');
        $pdo = null;
    }

    private function runMigrations(): void
    {
        $artisan = base_path('artisan');
        $cmd = sprintf(
            '%s %s migrate --database=pgsql_disposable --force --no-interaction 2>&1',
            PHP_BINARY,
            escapeshellarg($artisan)
        );
        $env = $_ENV;
        $env['DB_DATABASE'] = $this->dbName;
        $env['APP_ENV'] = 'testing';
        $this->execWithEnv($cmd, $env);
    }

    /**
     * Drop the disposable database.
     */
    public function tearDownDisposableDb(): void
    {
        try {
            $pdo = $this->adminPdo($this->baseDb);
            $pdo->exec('DROP DATABASE IF EXISTS "' . $this->dbName . '"');
        } catch (\Throwable) {
            // Best effort
        }
        // Cleanup result files
        if (is_dir($this->resultDir)) {
            @array_map('unlink', glob($this->resultDir . '/*'));
            @rmdir($this->resultDir);
        }
    }

    /**
     * Spawn multiple worker processes.
     *
     * @param  int    $count   Number of workers.
     * @param  string $scenario  Scenario identifier.
     * @param  array  $extra   Extra env vars per worker (indexed by worker index).
     * @return array<int, array|null>  Decoded JSON result per worker, or null on failure.
     */
    public function spawnWorkers(int $count, string $scenario, array $extra = []): array
    {
        @mkdir($this->resultDir, 0700, true);
        $barrier = $this->resultDir . '/barrier';
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $workerId = "w{$i}";
            $resultFile = $this->resultDir . "/result-{$workerId}.json";
            $workerEnv = array_merge([
                'IVORQ_DB_DATABASE' => $this->dbName,
                'IVORQ_DB_HOST'     => $this->dbHost,
                'IVORQ_DB_PORT'     => $this->dbPort,
                'IVORQ_DB_USERNAME' => $this->dbUser,
                'IVORQ_DB_PASSWORD' => $this->dbPass,
                'IVORQ_WORKER_ID'   => $workerId,
                'IVORQ_SCENARIO'    => $scenario,
                'IVORQ_RESULT_FILE' => $resultFile,
                'IVORQ_BARRIER'     => $barrier,
                'IVORQ_WORKER_INDEX' => (string) $i,
                'APP_ENV'           => 'testing',
            ], $extra[$i] ?? []);

            $workerScript = __DIR__ . '/GuestLedgerSettlementReadinessConcurrencyWorker.php';
            $cmd = sprintf('%s %s %s 2>&1',
                PHP_BINARY,
                escapeshellarg($workerScript),
                escapeshellarg(json_encode($workerEnv))
            );

            $stderrFile = $this->resultDir . "/stderr-{$workerId}.txt";
            $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
            $proc = proc_open($cmd, $spec, $pipes);
            if (is_resource($proc)) {
                $this->procs[$workerId] = ['proc' => $proc, 'pipes' => $pipes];
            }
        }

        // Wait for all workers
        foreach ($this->procs as $workerId => $pdata) {
            $exitCode = proc_close($pdata['proc']);
            $resultFile = $this->resultDir . "/result-{$workerId}.json";
            if (file_exists($resultFile)) {
                $results[] = json_decode(file_get_contents($resultFile), true);
            } else {
                $results[] = null;
            }
        }

        return $results;
    }

    private function adminPdo(string $dbName): \PDO
    {
        return new \PDO(
            "pgsql:host={$this->dbHost};port={$this->dbPort};dbname={$dbName}",
            $this->dbUser, $this->dbPass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    private function execWithEnv(string $cmd, array $env): void
    {
        $descriptorspec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);
        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
            proc_close($process);
        }
    }
}
