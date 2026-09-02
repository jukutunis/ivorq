<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class CostDeliveryConcurrencyProofTest extends PostgresTestCase
{
    public function test_same_message_same_scope_and_opposite_transfer_leg_concurrency(): void
    {
        $suffix = strtolower(Str::random(10));
        $barrierDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-p01e-'.$suffix;
        mkdir($barrierDir, 0777, true);
        $resultFile = $barrierDir.DIRECTORY_SEPARATOR.'result.json';
        $config = [
            'base_path' => base_path(),
            'db_name' => 'ivorq_concurrency_p01e_'.$suffix,
            'db_host' => config('database.connections.pgsql.host'),
            'db_port' => config('database.connections.pgsql.port'),
            'db_user' => config('database.connections.pgsql.username'),
            'db_pass' => config('database.connections.pgsql.password'),
            'barrier_dir' => $barrierDir,
            'result_file' => $resultFile,
        ];
        $configFile = $barrierDir.DIRECTORY_SEPARATOR.'config.json';
        file_put_contents($configFile, json_encode($config));
        $coordinator = base_path('tests/Postgres/Finance/CostControl/Support/DeferredCostDeliveryConcurrencyCoordinator.php');
        $process = proc_open(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($coordinator).' '.escapeshellarg($configFile),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;
        foreach (glob($barrierDir.DIRECTORY_SEPARATOR.'*') ?: [] as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        rmdir($barrierDir);

        $this->assertSame(0, $exit, $stdout."\n".$stderr);
        $this->assertIsArray($result, $stdout."\n".$stderr);
        $this->assertNull($result['error'], json_encode($result));
        $this->assertTrue($result['db_created']);
        $this->assertTrue($result['migrations_ok']);
        $this->assertTrue($result['db_dropped']);

        $this->assertSame(1, $result['same_message']['ledger']);
        $this->assertSame(1, $result['same_message']['attempts']);
        $this->assertEqualsCanonicalizing(
            ['DELIVERED', 'ALREADY_DELIVERED'],
            array_column($result['same_message']['workers'], 'status'),
        );

        $this->assertSame(2, $result['opposite_legs']['ledger']);
        $this->assertSame(2, $result['opposite_legs']['delivered_dispositions']);
        $this->assertEqualsCanonicalizing(
            ['DELIVERED', 'ALREADY_DELIVERED'],
            array_column($result['opposite_legs']['workers'], 'status'),
        );

        $this->assertContains($result['same_scope']['ledger'], [1, 2]);
        $this->assertSame($result['same_scope']['ledger'], $result['same_scope']['last_sequence']);
        $this->assertSame('DELIVERED', $result['same_scope']['states']['1']);
        if ($result['same_scope']['ledger'] === 1) {
            $this->assertSame('BLOCKED_SEQUENCE', $result['same_scope']['states']['2']);
        } else {
            $this->assertSame('DELIVERED', $result['same_scope']['states']['2']);
        }
    }
}
