<?php

namespace Tests\Postgres\Finance\Payables;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class SupplierInvoiceReceiptCapacityConcurrencyTest extends PostgresTestCase
{
    use DatabaseMigrations;

    public function test_two_distinct_registrations_cannot_both_consume_final_receipt_capacity(): void
    {
        Permission::firstOrCreate([
            'name' => SupplierInvoiceRegistrationService::PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = CompanyFactory::new()->create(['is_active' => true]);
        $property = PropertyFactory::new()->create([
            'company_id' => $company->id,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $actors = [
            UserFactory::new()->withProperty($property)->create(['is_active' => true]),
            UserFactory::new()->withProperty($property)->create(['is_active' => true]),
        ];

        foreach ($actors as $actor) {
            $actor->givePermissionTo(SupplierInvoiceRegistrationService::PERMISSION);
        }

        $fixture = $this->makeReceiptFixture($property, $actors[0]);
        $results = $this->spawnWorkers($property, $actors, $fixture);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['_exit_code'], $result['_stderr'] ?? ($result['error'] ?? ''));
        }

        $successful = collect($results)->where('status', 'registered')->values();
        $rejected = collect($results)->where('status', 'rejected')->values();

        $this->assertCount(1, $successful->all());
        $this->assertCount(1, $rejected->all());
        $this->assertStringContainsString('invoiceable lines', $rejected->first()['error']);
        $this->assertNotSame($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotSame($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);
        $this->assertSame(1, DB::table('vendor_invoices')->count());
        $this->assertSame(1, DB::table('vendor_invoice_lines')->count());
        $this->assertSame(1, DB::table('three_way_matches')->count());
        $this->assertEquals(
            1.0,
            (float) DB::table('vendor_invoice_lines')
                ->where('goods_receipt_line_id', $fixture['goods_receipt_line_id'])
                ->sum('quantity')
        );
    }

    private function makeReceiptFixture(Property $property, User $actor): array
    {
        $timestamp = now();
        $vendorCategoryId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $departmentId = (string) Str::ulid();
        $requestId = (string) Str::ulid();
        $purchaseOrderId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();
        $purchaseOrderLineId = (string) Str::ulid();
        $receiptId = (string) Str::ulid();
        $receiptLineId = (string) Str::ulid();

        DB::table('vendor_categories')->insert([
            'id' => $vendorCategoryId,
            'property_id' => $property->id,
            'category_code' => 'VC-CONC',
            'name' => 'Concurrency Vendor Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'vendor_category_id' => $vendorCategoryId,
            'vendor_code' => 'VND-CONC',
            'name' => 'Concurrency Vendor',
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => true,
            'performance_score' => 0,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('departments')->insert([
            'id' => $departmentId,
            'property_id' => $property->id,
            'name' => 'Concurrency Purchasing',
            'code' => 'PUR-CONC',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_requests')->insert([
            'id' => $requestId,
            'property_id' => $property->id,
            'request_no' => 'PR-CONC',
            'department_id' => $departmentId,
            'requester_id' => $actor->id,
            'required_date' => '2026-10-29',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'estimated_total' => 10,
            'status' => 'APPROVED',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_orders')->insert([
            'id' => $purchaseOrderId,
            'property_id' => $property->id,
            'po_no' => 'PO-CONC',
            'vendor_id' => $vendorId,
            'purchase_request_id' => $requestId,
            'issue_date' => '2026-10-28',
            'expected_delivery_date' => '2026-10-29',
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'subtotal' => 10,
            'tax_amount' => 0,
            'total_amount' => 10,
            'received_total' => 10,
            'status' => 'ISSUED',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_categories')->insert([
            'id' => $categoryId,
            'property_id' => $property->id,
            'name' => 'Concurrency Category',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $property->id,
            'code' => 'EA-CONC',
            'name' => 'Each',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_items')->insert([
            'id' => $itemId,
            'property_id' => $property->id,
            'sku' => 'SKU-CONC',
            'name' => 'Concurrency Item',
            'category_id' => $categoryId,
            'inventory_type' => 'stock',
            'criticality' => 'low',
            'is_batch_tracked' => false,
            'is_expiry_tracked' => false,
            'weighted_average_cost' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('inventory_locations')->insert([
            'id' => $locationId,
            'property_id' => $property->id,
            'name' => 'Concurrency Store',
            'type' => 'storeroom',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('purchase_order_lines')->insert([
            'id' => $purchaseOrderLineId,
            'purchase_order_id' => $purchaseOrderId,
            'inventory_item_id' => $itemId,
            'description' => 'Concurrency Item',
            'ordered_quantity' => 1,
            'received_quantity' => 1,
            'invoiced_quantity' => 0,
            'receiving_tolerance_percent' => 0,
            'unit_id' => $unitId,
            'unit_cost' => 10,
            'line_total' => 10,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('receiving_documents')->insert([
            'id' => $receiptId,
            'property_id' => $property->id,
            'vendor_id' => $vendorId,
            'purchase_order_id' => $purchaseOrderId,
            'grn_number' => 'GRN-CONC',
            'status' => 'approved',
            'received_at' => '2026-10-29 00:00:00',
            'received_by' => $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('receiving_lines')->insert([
            'id' => $receiptLineId,
            'receiving_document_id' => $receiptId,
            'purchase_order_line_id' => $purchaseOrderLineId,
            'inventory_item_id' => $itemId,
            'inventory_unit_id' => $unitId,
            'destination_location_id' => $locationId,
            'description' => 'Concurrency Item',
            'received_quantity' => 1,
            'unit_cost' => 10,
            'line_total' => 10,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return [
            'purchase_order_id' => $purchaseOrderId,
            'goods_receipt_id' => $receiptId,
            'goods_receipt_line_id' => $receiptLineId,
        ];
    }

    private function spawnWorkers(Property $property, array $actors, array $fixture): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fin-p01-r1-conc-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);

        $worker = __DIR__.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'SupplierInvoiceReceiptCapacityWorker.php';
        $barrier = $directory.DIRECTORY_SEPARATOR.'barrier';
        $runId = (string) Str::ulid();
        $processes = [];
        $created = [];

        try {
            foreach ($actors as $index => $actor) {
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
                    'property_id' => $property->id,
                    'actor_id' => $actor->id,
                    'purchase_order_id' => $fixture['purchase_order_id'],
                    'goods_receipt_id' => $fixture['goods_receipt_id'],
                ]));

                $command = sprintf(
                    '%s %s %s',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($worker),
                    escapeshellarg($argsFile)
                );
                $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
                $process = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ivorq_testing',
                ]));

                if (! is_resource($process)) {
                    $this->fail('Unable to spawn supplier-invoice capacity worker.');
                }

                fclose($pipes[0]);
                $processes[] = [
                    'process' => $process,
                    'result_file' => $resultFile,
                    'stderr_file' => $stderrFile,
                ];
            }

            $results = [];
            foreach ($processes as $process) {
                $exitCode = $this->waitForProcess($process['process'], 20);
                $decoded = is_file($process['result_file'])
                    ? json_decode((string) file_get_contents($process['result_file']), true)
                    : ['error' => 'missing result file'];
                $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
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
