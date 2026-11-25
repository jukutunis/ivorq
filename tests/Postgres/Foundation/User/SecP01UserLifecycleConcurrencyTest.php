<?php

namespace Tests\Postgres\Foundation\User;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;
use Tests\PostgresTestCase;

class SecP01UserLifecycleConcurrencyTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private array $workers = [];

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
            foreach ([$worker['arguments'], $worker['ready'], $worker['go'], $worker['result'], $worker['stderr']] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($worker['directory'])) {
                rmdir($worker['directory']);
            }
        }

        parent::tearDown();
    }

    public function test_competing_default_changes_converge_to_exactly_one_active_default(): void
    {
        [$actor, $target, $propertyA] = $this->fixtures();
        $propertyB = PropertyFactory::new()->create(['company_id' => $propertyA->company_id]);
        $propertyC = PropertyFactory::new()->create(['company_id' => $propertyA->company_id]);
        foreach ([$propertyB, $propertyC] as $property) {
            $target->properties()->attach($property->id, ['is_default' => false, 'status' => 'active', 'joined_at' => now()]);
        }

        $first = $this->spawn('set_default', $actor, $target, $propertyA->id, $propertyB->id);
        $second = $this->spawn('set_default', $actor, $target, $propertyA->id, $propertyC->id);
        $this->release([$first, $second]);
        $results = [$this->collect($first), $this->collect($second)];

        $this->assertSame(['success', 'success'], array_column($results, 'status'));
        $this->assertSame(1, DB::table('property_user')
            ->where('user_id', $target->id)
            ->where('status', 'active')
            ->where('is_default', true)
            ->count());
    }

    public function test_competing_deactivate_and_offboard_converge_to_terminal_neutralized_authority(): void
    {
        [$actor, $target, $property] = $this->fixtures();
        $deactivate = $this->spawn('deactivate', $actor, $target, $property->id);
        $offboard = $this->spawn('offboard', $actor, $target, $property->id);
        $this->release([$deactivate, $offboard]);
        $results = [$this->collect($deactivate), $this->collect($offboard)];

        $this->assertContains($results[0]['status'], ['success', 'controlled_rejection']);
        $this->assertContains($results[1]['status'], ['success', 'controlled_rejection']);
        $this->assertContains('success', array_column($results, 'status'));
        $terminal = User::withTrashed()->findOrFail($target->id);
        $this->assertFalse($terminal->is_active);
        $this->assertTrue($terminal->trashed());
        $this->assertSame(0, DB::table('property_user')->where('user_id', $target->id)->where('status', 'active')->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $target->id)->count());
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $target->id)->count());
    }

    private function fixtures(): array
    {
        $company = CompanyFactory::new()->create();
        $property = PropertyFactory::new()->create(['company_id' => $company->id]);
        $actor = UserFactory::new()->withProperty($property)->create(['is_active' => true]);
        $target = UserFactory::new()->withProperty($property)->create(['is_active' => true]);

        return [$actor, $target, $property];
    }

    private function spawn(string $action, User $actor, User $target, string $propertyId, ?string $defaultPropertyId = null): int
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sec-p01-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);
        $index = count($this->workers);
        $worker = [
            'directory' => $directory,
            'arguments' => $directory.DIRECTORY_SEPARATOR.'arguments.json',
            'ready' => $directory.DIRECTORY_SEPARATOR.'ready',
            'go' => $directory.DIRECTORY_SEPARATOR.'go',
            'result' => $directory.DIRECTORY_SEPARATOR.'result.json',
            'stderr' => $directory.DIRECTORY_SEPARATOR.'stderr.txt',
        ];
        file_put_contents($worker['arguments'], json_encode([
            'action' => $action,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'property_id' => $propertyId,
            'default_property_id' => $defaultPropertyId,
            'application_name' => "sec-p01-{$action}-{$index}",
            'ready_file' => $worker['ready'],
            'go_file' => $worker['go'],
            'result_file' => $worker['result'],
        ], JSON_THROW_ON_ERROR));
        $worker['process'] = proc_open(
            [PHP_BINARY, base_path('tests/Postgres/Foundation/User/Support/SecP01LifecycleWorker.php'), base_path(), $worker['arguments']],
            [['pipe', 'r'], ['file', $worker['stderr'], 'a'], ['file', $worker['stderr'], 'a']],
            $pipes,
            base_path(),
            array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'ivorq_testing']),
        );
        $this->assertIsResource($worker['process']);
        fclose($pipes[0]);
        $this->workers[$index] = $worker;

        return $index;
    }

    private function release(array $indexes): void
    {
        $deadline = microtime(true) + 20;
        foreach ($indexes as $index) {
            while (! is_file($this->workers[$index]['ready']) && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFileExists($this->workers[$index]['ready']);
        }
        foreach ($indexes as $index) {
            file_put_contents($this->workers[$index]['go'], 'go', LOCK_EX);
        }
    }

    private function collect(int $index): array
    {
        $worker = &$this->workers[$index];
        $deadline = microtime(true) + 35;
        do {
            $status = proc_get_status($worker['process']);
            if (! ($status['running'] ?? false)) {
                proc_close($worker['process']);
                $worker['process'] = null;
                $payload = is_file($worker['result'])
                    ? json_decode((string) file_get_contents($worker['result']), true, 512, JSON_THROW_ON_ERROR)
                    : ['status' => 'missing_result'];
                $payload['stderr'] = is_file($worker['stderr']) ? trim((string) file_get_contents($worker['stderr'])) : '';
                $this->assertNotSame('unexpected_error', $payload['status'], $payload['stderr'].' '.($payload['error'] ?? ''));

                return $payload;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail('SEC-P01 lifecycle worker timed out.');
    }
}
