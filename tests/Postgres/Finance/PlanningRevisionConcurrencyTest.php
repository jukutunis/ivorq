<?php

namespace Tests\Postgres\Finance;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Budgeting\Enums\BudgetVersionStatusEnum;
use Modules\Finance\Budgeting\Models\BudgetVersion;
use Modules\Finance\Budgeting\Services\BudgetService;
use Modules\Finance\GeneralLedger\Enums\AccountCategoryEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Tests\PostgresTestCase;

class PlanningRevisionConcurrencyTest extends PostgresTestCase
{
    use DatabaseMigrations;

    public function test_concurrent_budget_rejections_create_distinct_serialized_draft_revisions(): void
    {
        $propertyId = (string) Str::ulid();
        $service = app(BudgetService::class);
        $account = Account::create([
            'property_id' => $propertyId,
            'code' => 'BUD-CONC',
            'name' => 'Budget Concurrency',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Expense,
            'account_category' => AccountCategoryEnum::Expense,
            'is_cash_equivalent' => false,
        ]);
        $budget = $service->createBudget($propertyId, 2095, 'Concurrency budget', (string) Str::ulid());
        $versionOne = $budget->versions()->firstOrFail();
        $service->addLine($versionOne->id, null, $account->id, 1, 100, (string) Str::ulid());
        $service->submitVersion($versionOne->id, (string) Str::ulid());

        $versionTwo = new BudgetVersion;
        $versionTwo->forceFill([
            'budget_id' => $budget->id,
            'version_number' => 2,
            'status' => BudgetVersionStatusEnum::Draft,
            'created_by' => (string) Str::ulid(),
        ])->save();
        $service->addLine($versionTwo->id, null, $account->id, 2, 200, (string) Str::ulid());
        $service->submitVersion($versionTwo->id, (string) Str::ulid());

        $results = $this->spawnWorkers([
            [$versionOne->id, (string) Str::ulid()],
            [$versionTwo->id, (string) Str::ulid()],
        ]);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
            $this->assertSame('rejected', $result['status']);
        }
        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
        $this->assertSame(BudgetVersionStatusEnum::Rejected, $versionOne->fresh()->status);
        $this->assertSame(BudgetVersionStatusEnum::Rejected, $versionTwo->fresh()->status);

        $revisions = $budget->versions()->where('status', BudgetVersionStatusEnum::Draft)->orderBy('version_number')->get();
        $this->assertSame([3, 4], $revisions->pluck('version_number')->all());
        $this->assertSame(['100.0000', '200.0000'], $revisions->flatMap(fn (BudgetVersion $version) => $version->lines()->pluck('amount'))->map(fn ($amount) => (string) $amount)->sort()->values()->all());
        $this->assertSame(4, $budget->versions()->distinct('version_number')->count('version_number'));
        $this->assertSame(2, DB::table('budget_budget_approvals')->where('action', 'Rejected')->count());
    }

    private function spawnWorkers(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fin-p03-r1-conc-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);
        $worker = __DIR__.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'PlanningRevisionConcurrencyWorker.php';
        $barrier = $directory.DIRECTORY_SEPARATOR.'barrier';
        $runId = (string) Str::ulid();
        $processes = [];
        $created = [];

        try {
            foreach ($jobs as $index => [$versionId, $checkerId]) {
                $workerId = 'w'.$index;
                $argsFile = $directory.DIRECTORY_SEPARATOR."args-{$workerId}.json";
                $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$workerId}.json";
                $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$workerId}.txt";
                array_push($created, $argsFile, $resultFile, $stderrFile);
                file_put_contents($argsFile, json_encode([
                    'worker_id' => $workerId,
                    'run_id' => $runId,
                    'result_file' => $resultFile,
                    'barrier' => $barrier,
                    'version_id' => $versionId,
                    'checker_id' => $checkerId,
                ]));

                $command = sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($worker), escapeshellarg($argsFile));
                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $process = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ivorq_testing',
                ]));

                if (! is_resource($process)) {
                    $this->fail('Unable to spawn planning revision worker.');
                }

                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'result_file' => $resultFile, 'stderr_file' => $stderrFile];
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['process'], 20);
                $decoded = is_file($process['result_file'])
                    ? json_decode((string) file_get_contents($process['result_file']), true)
                    : ['error' => 'missing result file'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr_file']) ? trim((string) file_get_contents($process['stderr_file'])) : '';
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach (['ready-w0', 'ready-w1'] as $name) {
                $created[] = $barrier.'-'.$name.'.json';
            }
            foreach ($created as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }

    private function waitForProcess($process, int $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $observed = (int) ($status['exitcode'] ?? -1);
                $closed = proc_close($process);

                return $observed >= 0 ? $observed : $closed;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        return 124;
    }
}
