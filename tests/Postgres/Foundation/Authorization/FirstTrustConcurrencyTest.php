<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeFirstTrustAuthority;
use Tests\Postgres\Foundation\Authorization\Support\FirstTrustTestCase;

class FirstTrustConcurrencyTest extends FirstTrustTestCase
{
    public function test_two_independent_processes_have_one_authority_winner_and_one_foundation_universe(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq-b5a2b-concurrency-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $authorityState = $directory.DIRECTORY_SEPARATOR.'authority.json';
        $reserveBarrier = $directory.DIRECTORY_SEPARATOR.'reserve-barrier';
        $goFile = $directory.DIRECTORY_SEPARATOR.'go';
        FakeFirstTrustAuthority::initializeStateFile($authorityState, [
            'reserve_barrier_directory' => $reserveBarrier,
        ]);

        $workers = [];
        try {
            for ($index = 1; $index <= 2; $index++) {
                $workers[] = $this->spawnWorker($directory, $authorityState, $goFile, $index);
            }
            $this->waitForReady($workers);
            file_put_contents($goFile, 'go', LOCK_EX);
            $results = array_map(fn (array $worker): array => $this->collectWorker($worker), $workers);

            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'success'));
            $rejections = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 'rejected'));
            $this->assertGreaterThanOrEqual(1, count($successes), json_encode($results, JSON_THROW_ON_ERROR));
            foreach ($rejections as $rejection) {
                $this->assertContains($rejection['error'], [
                    'ALREADY_RESERVED_OTHER_EXECUTION',
                    'AUTHORITY_RECOVERY_REQUIRED',
                ]);
            }

            $state = (new FakeFirstTrustAuthority($authorityState))->snapshot();
            $this->assertSame('CONSUMED', $state['state']);
            $this->assertFoundationCounts(1);
            $this->assertSame(1, DB::table('owner_activation_tokens')->count());
            $this->assertSame('COMPLETED', DB::table('first_trust_runs')->value('status'));
            $this->assertSame($state['execution_id'], DB::table('first_trust_runs')->value('execution_id'));

            foreach (['personal_access_tokens', 'user_sessions', 'user_devices', 'model_has_roles', 'model_has_permissions'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
            }
            $this->removeTree($directory);
        }
    }

    private function spawnWorker(string $directory, string $stateFile, string $goFile, int $index): array
    {
        $argumentsFile = $directory.DIRECTORY_SEPARATOR."arguments-{$index}.json";
        $readyFile = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
        $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
        $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$index}.txt";
        file_put_contents($argumentsFile, json_encode([
            'release_sha' => $this->releaseSha,
            'bundle_path' => $this->bundlePath,
            'authority_state_file' => $stateFile,
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
