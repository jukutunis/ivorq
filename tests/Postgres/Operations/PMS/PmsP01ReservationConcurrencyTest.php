<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesPmsData;
use Tests\PostgresTestCase;

/** Two independent PHP and PostgreSQL sessions race on one Property. */
class PmsP01ReservationConcurrencyTest extends PostgresTestCase
{
    use CreatesPmsData;

    public function test_concurrent_create_allocates_distinct_numbers_and_complete_evidence(): void
    {
        $base = config('database.connections.pgsql');
        $database = 'ivorq_pms_p01_'.strtolower(Str::random(12));
        $admin = new \PDO(
            'pgsql:host='.$base['host'].';port='.$base['port'].';dbname=postgres',
            $base['username'], $base['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
        mkdir($dir, 0700, true);
        $admin->exec('CREATE DATABASE "'.$database.'"');

        $processes = [];
        try {
            config()->set('database.connections.pgsql.database', $database);
            DB::purge('pgsql');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));

            $company = $this->createCompany();
            $property = $this->createProperty($company);
            $actor = $this->createPropertyAdmin($property);
            $guest = $this->makePmsGuest($property);
            $go = $dir.DIRECTORY_SEPARATOR.'go';

            foreach (['a', 'b'] as $name) {
                $config = [
                    'database' => $database, 'base_path' => base_path(),
                    'property_id' => $property->id, 'actor_id' => $actor->id,
                    'guest_id' => $guest->id, 'go' => $go,
                    'ready' => $dir.DIRECTORY_SEPARATOR.'ready-'.$name,
                    'result' => $dir.DIRECTORY_SEPARATOR.'result-'.$name.'.json',
                ];
                $configFile = $dir.DIRECTORY_SEPARATOR.'worker-'.$name.'.json';
                file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR));
                $script = __DIR__.'/Support/PmsP01ReservationWorker.php';
                $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($configFile);
                $process = proc_open($command, [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[$name] = [$process, $pipes];
            }

            $this->waitFor([$dir.'/ready-a', $dir.'/ready-b']);
            file_put_contents($go, 'go');
            $this->waitFor([$dir.'/result-a.json', $dir.'/result-b.json']);
            $results = [];
            foreach (['a', 'b'] as $name) {
                $results[$name] = json_decode(file_get_contents($dir.'/result-'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
                [$process, $pipes] = $processes[$name];
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                unset($processes[$name]);
                $this->assertTrue($results[$name]['ok'], $results[$name]['error'] ?? 'Worker failed');
            }

            $this->assertNotSame($results['a']['php_pid'], $results['b']['php_pid']);
            $this->assertNotSame($results['a']['pg_pid'], $results['b']['pg_pid']);
            $this->assertEqualsCanonicalizing(['RES-00001', 'RES-00002'],
                [$results['a']['number'], $results['b']['number']]);
            $this->assertSame(2, DB::table('reservations')->where('property_id', $property->id)->count());
            $this->assertSame(2, DB::table('reservation_guests')->where('property_id', $property->id)->count());
            $this->assertSame(2, AuditLog::query()->where('property_id', $property->id)
                ->where('event', 'pms_reservation_created')->count());

            $conflicted = $this->makePmsReservation($property, $guest, [
                'reservation_number' => 'RES-00003',
                'status' => ReservationStatusEnum::Confirmed,
            ]);
            $lifecycleGo = $dir.DIRECTORY_SEPARATOR.'lifecycle-go';
            foreach (['c' => 'cancel', 'd' => 'no_show'] as $name => $action) {
                $config = [
                    'database' => $database, 'base_path' => base_path(),
                    'property_id' => $property->id, 'actor_id' => $actor->id,
                    'guest_id' => $guest->id, 'reservation_id' => $conflicted->id,
                    'action' => $action, 'go' => $lifecycleGo,
                    'ready' => $dir.DIRECTORY_SEPARATOR.'ready-'.$name,
                    'result' => $dir.DIRECTORY_SEPARATOR.'result-'.$name.'.json',
                ];
                $configFile = $dir.DIRECTORY_SEPARATOR.'worker-'.$name.'.json';
                file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR));
                $script = __DIR__.'/Support/PmsP01ReservationWorker.php';
                $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($configFile);
                $process = proc_open($command, [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[$name] = [$process, $pipes];
            }
            $this->waitFor([$dir.'/ready-c', $dir.'/ready-d']);
            file_put_contents($lifecycleGo, 'go');
            $this->waitFor([$dir.'/result-c.json', $dir.'/result-d.json']);
            $lifecycle = [];
            foreach (['c', 'd'] as $name) {
                $lifecycle[$name] = json_decode(file_get_contents($dir.'/result-'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
                [$process, $pipes] = $processes[$name];
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                unset($processes[$name]);
            }
            $this->assertNotSame($lifecycle['c']['php_pid'] ?? null, $lifecycle['d']['php_pid'] ?? null);
            $this->assertNotSame($lifecycle['c']['pg_pid'] ?? null, $lifecycle['d']['pg_pid'] ?? null);
            $this->assertSame(1, (int) $lifecycle['c']['ok'] + (int) $lifecycle['d']['ok']);
            $loser = $lifecycle['c']['ok'] ? $lifecycle['d'] : $lifecycle['c'];
            $this->assertStringContainsString('ValidationException', $loser['error']);
            $finalStatus = DB::table('reservations')->where('id', $conflicted->id)->value('status');
            $this->assertContains($finalStatus, ['cancelled', 'no_show']);
            $this->assertSame(1, AuditLog::query()->where('auditable_id', $conflicted->id)
                ->whereIn('event', ['pms_reservation_cancelled', 'pms_reservation_no_show'])->count());
        } finally {
            foreach ($processes as [$process, $pipes]) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_terminate($process);
                proc_close($process);
            }
            app(CurrentPropertyService::class)->clear();
            DB::purge('pgsql');
            config()->set('database.connections.pgsql.database', $base['database']);
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    private function waitFor(array $files): void
    {
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            if (count(array_filter($files, 'file_exists')) === count($files)) {
                return;
            }
            usleep(20000);
        }
        $this->fail('Concurrent workers did not reach the barrier or publish results.');
    }
}
