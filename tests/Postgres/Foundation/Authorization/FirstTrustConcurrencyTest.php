<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeFirstTrustAuthority;
use Tests\Postgres\Foundation\Authorization\Support\FirstTrustTestCase;

class FirstTrustConcurrencyTest extends FirstTrustTestCase
{
    public function test_two_independent_processes_have_one_authority_winner_and_one_foundation_universe(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq-b5a2b-concurrency-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $authorityState = $directory.DIRECTORY_SEPARATOR.'authority.json';
        $reserveBarrier = $directory.DIRECTORY_SEPARATOR.'reserve-barrier';
        $goFile = $directory.DIRECTORY_SEPARATOR.'go';
        FakeFirstTrustAuthority::initializeStateFile($authorityState, [
            'reserve_barrier_directory' => $reserveBarrier,
        ]);

        $parentSession = $this->assertDeterministicStartingState();
        DB::disconnect('pgsql');
        DB::purge('pgsql');

        $workers = [];
        $results = [];
        $passed = false;
        $goReleasedAt = null;
        try {
            for ($index = 1; $index <= 2; $index++) {
                $workers[] = $this->spawnWorker($directory, $authorityState, $reserveBarrier, $goFile, $index);
            }
            $this->waitForReady($workers);
            $goReleasedAt = hrtime(true);
            if (file_put_contents($goFile, 'go', LOCK_EX) === false) {
                $this->fail('Unable to release the concurrency start barrier.');
            }

            $this->waitForReserveArrivals($workers, $reserveBarrier);
            if (file_put_contents($reserveBarrier.DIRECTORY_SEPARATOR.'release', 'release', LOCK_EX) === false) {
                $this->fail('Unable to release the reserve barrier.');
            }
            $results = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);

            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'success'));
            $rejections = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'expected_authority_rejection'));
            $harnessErrors = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'test_harness_error'));
            $unexpectedErrors = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'unexpected_error'));
            $diagnostic = $this->diagnosticMessage($results, $directory);

            $this->assertCount(1, $successes, $diagnostic);
            $this->assertCount(1, $rejections, $diagnostic);
            $this->assertCount(0, $harnessErrors, $diagnostic);
            $this->assertCount(0, $unexpectedErrors, $diagnostic);
            $this->assertSame('ALREADY_RESERVED_OTHER_EXECUTION', $rejections[0]['error'], $diagnostic);
            $this->assertWorkerTiming($results, $goReleasedAt);

            $this->assertCount(2, glob($reserveBarrier.DIRECTORY_SEPARATOR.'reserve-arrived-*.json') ?: []);
            $this->assertCount(2, glob($reserveBarrier.DIRECTORY_SEPARATOR.'reserve-released-*.json') ?: []);
            $this->assertCount(1, glob($reserveBarrier.DIRECTORY_SEPARATOR.'reserve-completed-*.json') ?: []);
            $this->assertCount(1, glob($reserveBarrier.DIRECTORY_SEPARATOR.'consume-started-*.json') ?: []);
            $this->assertCount(1, glob($reserveBarrier.DIRECTORY_SEPARATOR.'consume-completed-*.json') ?: []);
            $this->assertSame([], glob($reserveBarrier.DIRECTORY_SEPARATOR.'test-harness-error-*.json') ?: []);

            $state = (new FakeFirstTrustAuthority($authorityState))->snapshot();
            $this->assertSame('CONSUMED', $state['state']);
            $this->assertFoundationCounts(1);
            $this->assertSame(1, DB::table('owner_activation_tokens')->count());
            $this->assertSame('COMPLETED', DB::table('first_trust_runs')->value('status'));
            $this->assertSame($state['execution_id'], DB::table('first_trust_runs')->value('execution_id'));

            foreach (['personal_access_tokens', 'user_sessions', 'user_devices', 'model_has_roles', 'model_has_permissions'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }

            $passed = true;
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
            }
            if ($passed) {
                $this->removeTree($directory);
            } else {
                $this->preserveDiagnostics($directory, $results, $parentSession, $goReleasedAt);
                fwrite(STDERR, "Concurrency diagnostics preserved at {$directory}".PHP_EOL);
            }
        }
    }

    /** @return array<string,mixed> */
    private function assertDeterministicStartingState(): array
    {
        foreach ([
            'first_trust_runs',
            'users',
            'companies',
            'properties',
            'property_user',
            'owner_activations',
            'owner_activation_tokens',
            'identity_security_events',
            'model_has_roles',
            'model_has_permissions',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        foreach (['activity_log', 'activity_logs', 'audit_logs'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        }

        $this->assertSame(0, DB::transactionLevel(), 'Parent Laravel transaction level');
        $this->assertFalse(DB::connection('pgsql')->getPdo()->inTransaction(), 'Parent PDO transaction state');
        $session = (array) DB::selectOne(<<<'SQL'
            SELECT pg_backend_pid() AS pid,
                   current_setting('application_name') AS application_name,
                   current_setting('lock_timeout') AS lock_timeout,
                   current_setting('statement_timeout') AS statement_timeout,
                   txid_current_if_assigned() IS NULL AS transaction_id_unassigned,
                   (SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory') AS advisory_lock_count
            SQL);
        $this->assertTrue((bool) $session['transaction_id_unassigned'], 'Parent transaction ID must be unassigned');
        $this->assertSame(0, (int) $session['advisory_lock_count'], 'Parent advisory lock count');

        return $session;
    }

    private function spawnWorker(
        string $directory,
        string $stateFile,
        string $reserveBarrier,
        string $goFile,
        int $index,
    ): array {
        $argumentsFile = $directory.DIRECTORY_SEPARATOR."arguments-{$index}.json";
        $readyFile = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
        $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
        $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$index}.txt";
        file_put_contents($argumentsFile, json_encode([
            'release_sha' => $this->releaseSha,
            'bundle_path' => $this->bundlePath,
            'authority_state_file' => $stateFile,
            'reserve_barrier_directory' => $reserveBarrier,
            'application_name' => "b5a2b-concurrency-{$index}",
            'ready_file' => $readyFile,
            'go_file' => $goFile,
            'result_file' => $resultFile,
        ], JSON_THROW_ON_ERROR), LOCK_EX);

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Postgres/Foundation/Authorization/Support/FirstTrustConcurrencyWorker.php'), base_path(), $argumentsFile],
            [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']],
            $pipes,
            base_path(),
            array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'ivorq_testing',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ]),
        );
        if (! is_resource($process)) {
            $this->fail('Unable to spawn first-trust concurrency worker.');
        }
        fclose($pipes[0]);

        return compact('process', 'argumentsFile', 'readyFile', 'resultFile', 'stderrFile');
    }

    private function waitForReady(array $workers): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($workers, static fn (array $worker): bool => is_file($worker['readyFile']))) === count($workers)) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        $this->fail('Concurrency workers did not reach the start barrier.');
    }

    private function waitForReserveArrivals(array $workers, string $reserveBarrier): void
    {
        $deadline = microtime(true) + 20;
        do {
            $arrivals = glob($reserveBarrier.DIRECTORY_SEPARATOR.'reserve-arrived-*.json') ?: [];
            if (count($arrivals) === count($workers)) {
                $this->addToAssertionCount(1);

                return;
            }
            foreach ($workers as $worker) {
                if (is_file($worker['resultFile'])) {
                    $this->fail('A concurrency worker exited before both workers reached reserve: '.$this->diagnosticMessage([], dirname($reserveBarrier)));
                }
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        $this->fail('Concurrency workers did not both reach reserve: '.$this->diagnosticMessage([], dirname($reserveBarrier)));
    }

    private function collectWorker(array $worker): array
    {
        $deadline = microtime(true) + 45;
        do {
            $status = proc_get_status($worker['process']);
            if (! ($status['running'] ?? false)) {
                proc_close($worker['process']);
                $result = is_file($worker['resultFile'])
                    ? json_decode((string) file_get_contents($worker['resultFile']), true)
                    : null;
                if (! is_array($result)) {
                    $stderr = is_file($worker['stderrFile']) ? file_get_contents($worker['stderrFile']) : '';
                    $this->fail('Concurrency worker produced no result: '.$stderr);
                }

                return $result;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail('Concurrency worker timed out.');
    }

    private function assertWorkerTiming(array $results, int $goReleasedAt): void
    {
        foreach ($results as $result) {
            $timing = $result['timing'] ?? [];
            foreach (['worker_started_ns', 'application_booted_ns', 'ready_ns', 'go_observed_ns', 'establish_started_ns', 'finished_ns'] as $key) {
                $this->assertIsInt($timing[$key] ?? null, $key);
            }
            $this->assertLessThanOrEqual($timing['application_booted_ns'], $timing['worker_started_ns']);
            $this->assertLessThanOrEqual($timing['ready_ns'], $timing['application_booted_ns']);
            $this->assertLessThanOrEqual($timing['go_observed_ns'], $timing['ready_ns']);
            $this->assertGreaterThanOrEqual($goReleasedAt, $timing['go_observed_ns']);
            $this->assertLessThanOrEqual($timing['establish_started_ns'], $timing['go_observed_ns']);
            $this->assertLessThanOrEqual($timing['finished_ns'], $timing['establish_started_ns']);

            $session = $result['database_session'] ?? [];
            $this->assertSame(0, (int) ($session['advisory_lock_count'] ?? -1));
            $this->assertSame('20s', $session['lock_timeout'] ?? null);
            $this->assertSame('30s', $session['statement_timeout'] ?? null);
        }
    }

    private function diagnosticMessage(array $results, string $directory): string
    {
        $resultFiles = [];
        foreach (glob($directory.DIRECTORY_SEPARATOR.'result-*.json') ?: [] as $file) {
            $resultFiles[basename($file)] = json_decode((string) file_get_contents($file), true);
        }
        $stderr = [];
        foreach (glob($directory.DIRECTORY_SEPARATOR.'stderr-*.txt') ?: [] as $file) {
            $stderr[basename($file)] = trim((string) file_get_contents($file));
        }
        $state = is_file($directory.DIRECTORY_SEPARATOR.'authority.json')
            ? json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'authority.json'), true)
            : null;

        return json_encode([
            'results' => $results,
            'result_files' => $resultFiles,
            'stderr' => $stderr,
            'authority_state' => $state,
            'barrier_files' => array_map('basename', glob($directory.DIRECTORY_SEPARATOR.'reserve-barrier'.DIRECTORY_SEPARATOR.'*') ?: []),
            'diagnostic_directory' => $directory,
        ], JSON_THROW_ON_ERROR);
    }

    private function preserveDiagnostics(
        string $directory,
        array $results,
        array $parentSession,
        ?int $goReleasedAt,
    ): void {
        if (! is_dir($directory)) {
            return;
        }
        $payload = [
            'captured_at' => gmdate(DATE_ATOM),
            'parent_session' => $parentSession,
            'go_released_at_ns' => $goReleasedAt,
            'results' => $results,
            'diagnostic' => json_decode($this->diagnosticMessage($results, $directory), true, 512, JSON_THROW_ON_ERROR),
        ];
        @file_put_contents(
            $directory.DIRECTORY_SEPARATOR.'diagnostics.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    private function terminateWorker(array $worker): void
    {
        if (! is_resource($worker['process'])) {
            return;
        }
        $status = proc_get_status($worker['process']);
        if ($status['running'] ?? false) {
            proc_terminate($worker['process']);
        }
        @proc_close($worker['process']);
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
