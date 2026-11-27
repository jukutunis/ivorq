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

    public function test_folio_identity_and_guest_deletion_races_preserve_live_relationships(): void
    {
        $base = config('database.connections.pgsql');
        $database = 'ivorq_pms_p01_r1_'.strtolower(Str::random(10));
        $admin = new \PDO('pgsql:host='.$base['host'].';port='.$base['port'].';dbname=postgres',
            $base['username'], $base['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
        mkdir($dir, 0700, true);
        $admin->exec('CREATE DATABASE "'.$database.'"');

        try {
            config()->set('database.connections.pgsql.database', $database);
            DB::purge('pgsql');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $company = $this->createCompany();
            $property = $this->createProperty($company);
            $actor = $this->createPropertyAdmin($property);
            $primary = $this->makePmsGuest($property);
            $replacement = $this->makePmsGuest($property);
            $reservation = $this->makePmsReservation($property, $primary);
            $common = ['property_id' => $property->id, 'actor_id' => $actor->id];

            $folio = $this->raceR1($database, $dir, 'folio',
                ['action' => 'folio_open', 'reservation_id' => $reservation->id,
                    'idempotency_key' => 'r1-folio-race'] + $common,
                ['action' => 'replace_primary', 'reservation_id' => $reservation->id,
                    'guest_id' => $replacement->id] + $common);
            $this->assertTrue($folio[0]['ok'], $folio[0]['error'] ?? 'Folio opening failed');
            if ($folio[1]['ok']) {
                $this->assertSame($replacement->id, $folio[1]['guest_id']);
            } else {
                $this->assertStringContainsString('HttpException', $folio[1]['error']);
            }
            $this->assertSame($folio[0]['guest_id'], DB::table('folios')
                ->where('id', $folio[0]['folio_id'])->value('guest_id'));
            $this->assertSame($folio[0]['guest_id'], DB::table('reservations')
                ->where('id', $reservation->id)->value('primary_guest_id'));
            $this->assertSame(1, DB::table('folios')->where('reservation_id', $reservation->id)->count());

            $candidate = $this->makePmsGuest($property);
            $create = $this->raceR1($database, $dir, 'create',
                ['action' => 'guest_delete', 'guest_id' => $candidate->id] + $common,
                ['action' => 'create', 'guest_id' => $candidate->id] + $common);
            $this->assertSame(1, (int) $create[0]['ok'] + (int) $create[1]['ok']);
            $this->assertStringContainsString($create[0]['ok'] ? 'ValidationException' : 'HttpException',
                $create[0]['ok'] ? $create[1]['error'] : $create[0]['error']);
            $this->assertSame((bool) DB::table('reservations')->where('property_id', $property->id)
                ->where('primary_guest_id', $candidate->id)->exists(), $create[1]['ok']);
            $this->assertSame($create[1]['ok'], DB::table('guests')->where('id', $candidate->id)
                ->whereNull('deleted_at')->exists());

            $associateCandidate = $this->makePmsGuest($property);
            $associationReservation = $this->makePmsReservation($property, $primary);
            $association = $this->raceR1($database, $dir, 'associate',
                ['action' => 'guest_delete', 'guest_id' => $associateCandidate->id] + $common,
                ['action' => 'guest_associate', 'reservation_id' => $associationReservation->id,
                    'guest_id' => $associateCandidate->id] + $common);
            $this->assertSame(1, (int) $association[0]['ok'] + (int) $association[1]['ok']);
            $this->assertStringContainsString('HttpException',
                $association[0]['ok'] ? $association[1]['error'] : $association[0]['error']);
            $this->assertSame($association[1]['ok'], DB::table('reservation_guests')
                ->where('property_id', $property->id)->where('reservation_id', $associationReservation->id)
                ->where('guest_id', $associateCandidate->id)->exists());
            $this->assertSame($association[1]['ok'], DB::table('guests')
                ->where('id', $associateCandidate->id)->whereNull('deleted_at')->exists());
        } finally {
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

    private function raceR1(string $database, string $dir, string $label, array $a, array $b): array
    {
        $go = $dir.DIRECTORY_SEPARATOR.'go-'.$label;
        $processes = [];
        try {
            foreach ([$a, $b] as $index => $config) {
                $config += ['database' => $database, 'base_path' => base_path(),
                    'go' => $go, 'ready' => $dir.'/ready-'.$label.'-'.$index,
                    'result' => $dir.'/result-'.$label.'-'.$index.'.json'];
                $file = $dir.'/worker-'.$label.'-'.$index.'.json';
                file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
                $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/Support/PmsP01ReservationWorker.php')
                    .' '.escapeshellarg($file);
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $this->waitFor([$dir.'/ready-'.$label.'-0', $dir.'/ready-'.$label.'-1']);
            file_put_contents($go, 'go');
            $this->waitFor([$dir.'/result-'.$label.'-0.json', $dir.'/result-'.$label.'-1.json']);
            $results = [];
            foreach ([0, 1] as $index) {
                $results[] = json_decode(file_get_contents($dir.'/result-'.$label.'-'.$index.'.json'), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
            $this->assertNotSame($results[0]['pg_pid'], $results[1]['pg_pid']);
            return $results;
        } finally {
            foreach ($processes as [$process, $pipes]) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
        }
    }

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
