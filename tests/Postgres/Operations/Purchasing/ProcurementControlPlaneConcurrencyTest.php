<?php

namespace Tests\Postgres\Operations\Purchasing;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderRevisionSnapshot;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseRequestRevisionSnapshot;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Services\PurchaseOrderService;
use Modules\Operations\Purchasing\Services\PurchaseRequestService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\PostgresTestCase;

class ProcurementControlPlaneConcurrencyTest extends PostgresTestCase
{
    use CreatesFoundationData;
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        if (app()->bound('db')) {
            DB::table('tasks')->whereRaw('char_length(taskable_type) > 50')->delete();
        }

        parent::tearDown();
    }

    public function test_real_workers_serialize_numbering_conversion_and_competing_decisions(): void
    {
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        $company = $this->createCompany();
        $property = $this->createProperty($company, ['currency' => 'IDR']);
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $maker = $this->user($property, 'proc-maker@example.test', false);
        $approver = $this->user($property, 'proc-approver@example.test', true);
        $rejector = $this->user($property, 'proc-rejector@example.test', true);
        [$item, $unit, $vendor] = $this->commercialFixture($property);
        $department = $this->createDepartment($property);

        $numberResults = $this->spawnWorkers([
            ['mode' => 'number', 'property_id' => $property->id, 'document_type' => 'PR', 'year' => 2097],
            ['mode' => 'number', 'property_id' => $property->id, 'document_type' => 'PR', 'year' => 2097],
        ]);
        $this->assertSuccessfulWorkers($numberResults);
        $this->assertSame(['allocated', 'allocated'], array_column($numberResults, 'status'));
        $this->assertCount(2, array_unique(array_column($numberResults, 'number')));
        $this->assertEqualsCanonicalizing(['PR-2097-000001', 'PR-2097-000002'], array_column($numberResults, 'number'));

        $creationJob = [
            'mode' => 'create_request',
            'property_id' => $property->id,
            'actor_id' => $maker->id,
            'department_id' => $department->id,
            'inventory_item_id' => $item->id,
            'unit_id' => $unit->id,
            'required_date' => now()->addDays(5)->toDateString(),
            'idempotency_key' => 'concurrent-pr-replay',
        ];
        $creationResults = $this->spawnWorkers([$creationJob, $creationJob]);
        $this->assertSuccessfulWorkers($creationResults);
        $this->assertSame(['created', 'created'], array_column($creationResults, 'status'));
        $this->assertSame($creationResults[0]['purchase_request_id'], $creationResults[1]['purchase_request_id']);
        $this->assertSame($creationResults[0]['request_no'], $creationResults[1]['request_no']);
        $this->assertSame(1, PurchaseRequest::query()->where('creation_idempotency_key', 'concurrent-pr-replay')->count());

        $conversionRequest = $this->draftRequest($property, $department->id, $item, $unit, $maker, 'conversion-race');
        $conversionRequest->update(['status' => PurchaseRequestStatusEnum::Approved]);
        $conversionResults = $this->spawnWorkers([
            [
                'mode' => 'convert',
                'property_id' => $property->id,
                'actor_id' => $maker->id,
                'purchase_request_id' => $conversionRequest->id,
                'vendor_id' => $vendor->id,
                'expected_delivery_date' => now()->addWeek()->toDateString(),
            ],
            [
                'mode' => 'convert',
                'property_id' => $property->id,
                'actor_id' => $maker->id,
                'purchase_request_id' => $conversionRequest->id,
                'vendor_id' => $vendor->id,
                'expected_delivery_date' => now()->addWeek()->toDateString(),
            ],
        ]);
        $this->assertSuccessfulWorkers($conversionResults);
        $this->assertSame(1, collect($conversionResults)->where('status', 'converted')->count());
        $this->assertSame(1, collect($conversionResults)->where('status', 'business_error')->count());
        $this->assertSame(1, PurchaseOrder::query()->where('purchase_request_id', $conversionRequest->id)->count());
        $this->assertSame(PurchaseRequestStatusEnum::Converted, $conversionRequest->fresh()->status);

        $this->workflow($property, PurchaseRequest::class);
        $decisionRequest = $this->draftRequest($property, $department->id, $item, $unit, $maker, 'decision-race', '0');
        app(PurchaseRequestService::class)->submit($decisionRequest->id, $maker);
        $decisionResults = $this->spawnWorkers([
            [
                'mode' => 'decision',
                'decision' => 'approve',
                'property_id' => $property->id,
                'actor_id' => $approver->id,
                'purchase_request_id' => $decisionRequest->id,
            ],
            [
                'mode' => 'decision',
                'decision' => 'reject',
                'property_id' => $property->id,
                'actor_id' => $rejector->id,
                'purchase_request_id' => $decisionRequest->id,
            ],
        ]);
        $this->assertSuccessfulWorkers($decisionResults);
        $this->assertSame(1, collect($decisionResults)->where('status', 'decided')->count());
        $this->assertSame(1, collect($decisionResults)->where('status', 'business_error')->count());
        $this->assertContains($decisionRequest->fresh()->status, [
            PurchaseRequestStatusEnum::Approved,
            PurchaseRequestStatusEnum::Rejected,
        ]);
    }

    public function test_real_workers_serialize_rejected_pr_and_po_revision_creation(): void
    {
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        $company = $this->createCompany();
        $property = $this->createProperty($company, ['currency' => 'IDR']);
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $maker = $this->user($property, 'revision-race-maker@example.test', false);
        $reviser = $this->user($property, 'revision-race-reviser@example.test', true);
        $rejector = $this->user($property, 'revision-race-rejector@example.test', true);
        [$item, $unit, $vendor] = $this->commercialFixture($property);
        $department = $this->createDepartment($property);

        $this->workflow($property, PurchaseRequest::class);
        $request = $this->draftRequest($property, $department->id, $item, $unit, $maker, 'revision-race-pr', '0');
        $requestService = app(PurchaseRequestService::class);
        $requestService->submit($request->id, $maker);
        $requestService->reject($request->id, $rejector, 'Rejected before concurrent revision.');

        $prJob = [
            'mode' => 'revise_request',
            'property_id' => $property->id,
            'actor_id' => $reviser->id,
            'purchase_request_id' => $request->id,
        ];
        $prResults = $this->spawnWorkers([$prJob, $prJob]);
        $this->assertSuccessfulWorkers($prResults);
        $this->assertSame(1, collect($prResults)->where('status', 'revised')->count());
        $this->assertSame(1, collect($prResults)->where('status', 'business_error')->count());
        $this->assertSame(2, $request->fresh()->revision_number);
        $this->assertSame(1, PurchaseRequestRevisionSnapshot::query()->where('purchase_request_id', $request->id)->count());

        $poSource = $this->draftRequest($property, $department->id, $item, $unit, $maker, 'revision-race-po-source');
        $poSource->update(['status' => PurchaseRequestStatusEnum::Approved]);
        $orderService = app(PurchaseOrderService::class);
        $order = $orderService->createFromApprovedPR($poSource->id, $vendor->id, [
            'expected_delivery_date' => now()->addWeek()->toDateString(),
        ], $maker);
        $this->workflow($property, PurchaseOrder::class);
        $orderService->submit($order->id, $maker);
        $orderService->reject($order->id, $rejector, 'Rejected before concurrent revision.');

        $poJob = [
            'mode' => 'revise_order',
            'property_id' => $property->id,
            'actor_id' => $reviser->id,
            'purchase_order_id' => $order->id,
        ];
        $poResults = $this->spawnWorkers([$poJob, $poJob]);
        $this->assertSuccessfulWorkers($poResults);
        $this->assertSame(1, collect($poResults)->where('status', 'revised')->count());
        $this->assertSame(1, collect($poResults)->where('status', 'business_error')->count());
        $this->assertSame(2, $order->fresh()->revision_number);
        $this->assertSame(1, PurchaseOrderRevisionSnapshot::query()->where('purchase_order_id', $order->id)->count());
        $this->assertSame(1, PurchaseOrder::query()->where('purchase_request_id', $poSource->id)->count());
    }

    private function draftRequest(
        Property $property,
        string $departmentId,
        InventoryItem $item,
        InventoryUnit $unit,
        User $maker,
        string $idempotencyKey,
        string $estimatedUnitCost = '10',
    ): PurchaseRequest {
        app(CurrentPropertyService::class)->setPropertyId($property->id);

        return app(PurchaseRequestService::class)->createWithLines([
            'department_id' => $departmentId,
            'required_date' => now()->addDays(5)->toDateString(),
            'idempotency_key' => $idempotencyKey,
        ], [[
            'inventory_item_id' => $item->id,
            'unit_id' => $unit->id,
            'quantity' => '2',
            'estimated_unit_cost' => $estimatedUnitCost,
        ]], $maker);
    }

    private function commercialFixture(Property $property): array
    {
        $inventoryCategory = InventoryCategory::create([
            'property_id' => $property->id,
            'name' => 'Concurrency supplies',
        ]);
        $item = InventoryItem::create([
            'property_id' => $property->id,
            'category_id' => $inventoryCategory->id,
            'sku' => 'CONC-001',
            'name' => 'Concurrency Supply',
            'inventory_type' => 'goods',
            'is_active' => true,
        ]);
        $unit = InventoryUnit::create([
            'property_id' => $property->id,
            'code' => 'EA',
            'name' => 'Each',
        ]);
        $vendorCategory = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'CONC',
            'name' => 'Concurrency vendors',
        ]);
        $vendor = Vendor::create([
            'company_id' => $property->company_id,
            'vendor_category_id' => $vendorCategory->id,
            'vendor_code' => 'CONC-VENDOR',
            'name' => 'Concurrency Vendor',
            'is_active' => true,
            'is_approved' => true,
        ]);

        return [$item, $unit, $vendor];
    }

    private function user(Property $property, string $email, bool $systemAdmin): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'is_system_admin' => $systemAdmin,
        ]);
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function workflow(Property $property, string $type): void
    {
        $workflow = ApprovalWorkflow::create([
            'property_id' => $property->id,
            'approvable_type' => $type,
            'name' => class_basename($type).' concurrency approval',
            'is_active' => true,
        ]);
        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Independent checker',
            'required_approvals' => 1,
        ]);
    }

    private function spawnWorkers(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proc-p01-conc-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);
        $worker = __DIR__.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'ProcurementControlPlaneConcurrencyWorker.php';
        $barrier = $directory.DIRECTORY_SEPARATOR.'barrier';
        $runId = (string) Str::ulid();
        $processes = [];
        $created = [];

        try {
            foreach ($jobs as $index => $job) {
                $workerId = 'w'.$index;
                $argsFile = $directory.DIRECTORY_SEPARATOR."args-{$workerId}.json";
                $resultFile = $directory.DIRECTORY_SEPARATOR."result-{$workerId}.json";
                $stderrFile = $directory.DIRECTORY_SEPARATOR."stderr-{$workerId}.txt";
                array_push($created, $argsFile, $resultFile, $stderrFile);
                file_put_contents($argsFile, json_encode($job + [
                    'worker_id' => $workerId,
                    'run_id' => $runId,
                    'result_file' => $resultFile,
                    'barrier' => $barrier,
                ], JSON_THROW_ON_ERROR));

                $command = sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($worker), escapeshellarg($argsFile));
                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $process = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ivorq_testing',
                ]));

                if (! is_resource($process)) {
                    $this->fail('Unable to spawn Procurement concurrency worker.');
                }

                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'result_file' => $resultFile, 'stderr_file' => $stderrFile];
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['process'], 25);
                $decoded = is_file($process['result_file'])
                    ? json_decode((string) file_get_contents($process['result_file']), true)
                    : ['status' => 'missing_result'];
                $decoded = is_array($decoded) ? $decoded : ['status' => 'malformed_result'];
                $decoded['_exit_code'] = $exitCode;
                $decoded['_stderr'] = is_file($process['stderr_file'])
                    ? trim((string) file_get_contents($process['stderr_file']))
                    : '';
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

    private function assertSuccessfulWorkers(array $results): void
    {
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?: ($result['error'] ?? ''));
            $this->assertNotSame('harness_error', $result['status'], $result['error'] ?? '');
        }
        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
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
