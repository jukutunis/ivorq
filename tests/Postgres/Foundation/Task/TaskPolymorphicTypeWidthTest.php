<?php

namespace Tests\Postgres\Foundation\Task;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class TaskPolymorphicTypeWidthTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private PurchaseRequest $purchaseRequest;

    private PurchaseOrder $purchaseOrder;

    private ReceivingDocument $receivingDocument;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $department = Department::create([
            'property_id' => $this->property->id,
            'name' => 'Task width '.Str::random(8),
            'code' => 'TW'.Str::upper(Str::random(6)),
        ]);
        $this->purchaseRequest = PurchaseRequest::create([
            'property_id' => $this->property->id,
            'request_no' => 'PR-TW-'.Str::upper(Str::random(8)),
            'department_id' => $department->id,
            'requester_id' => $this->actor->id,
            'required_date' => now()->addWeek(),
            'estimated_total' => 100,
            'status' => PurchaseRequestStatusEnum::Draft,
        ]);

        $vendorCategory = VendorCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'TW'.Str::upper(Str::random(6)),
            'name' => 'Task width vendor '.Str::random(8),
            'is_active' => true,
        ]);
        $vendor = Vendor::create([
            'property_id' => $this->property->id,
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $vendorCategory->id,
            'vendor_code' => 'TWV'.Str::upper(Str::random(6)),
            'name' => 'Task width vendor',
            'is_active' => true,
            'is_approved' => true,
        ]);
        $this->purchaseOrder = PurchaseOrder::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $this->purchaseRequest->id,
            'po_no' => 'PO-TW-'.Str::upper(Str::random(8)),
            'issue_date' => now(),
            'expected_delivery_date' => now()->addWeek(),
            'status' => PurchaseOrderStatusEnum::Draft,
            'created_by' => $this->actor->id,
        ]);
        $this->receivingDocument = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $this->purchaseOrder->id,
            'grn_number' => 'GRN-TW-'.Str::upper(Str::random(8)),
            'received_by' => $this->actor->id,
        ]);
    }

    public function test_schema_is_nullable_varchar_255_and_preserves_composite_index(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT data_type, character_maximum_length, is_nullable
              FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'tasks'
               AND column_name = 'taskable_type'
            SQL);

        $this->assertSame('character varying', $column->data_type);
        $this->assertSame(255, (int) $column->character_maximum_length);
        $this->assertSame('YES', $column->is_nullable);

        $indexes = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'tasks'"))
            ->pluck('indexdef');
        $this->assertTrue($indexes->contains(
            fn (string $definition): bool => str_contains($definition, '(taskable_type, taskable_id)')
        ));
    }

    public function test_purchase_request_purchase_order_and_receiving_fqcns_persist_and_resolve(): void
    {
        foreach ([$this->purchaseRequest, $this->purchaseOrder, $this->receivingDocument] as $taskable) {
            $task = $this->createTask($taskable::class, $taskable->id);

            $this->assertSame(
                $taskable::class,
                DB::table('tasks')->where('id', $task->id)->value('taskable_type'),
            );
            $resolved = Task::withoutGlobalScopes()->findOrFail($task->id)->taskable;
            $this->assertInstanceOf($taskable::class, $resolved);
            $this->assertSame($taskable->id, $resolved->id);
        }
    }

    public function test_migration_round_trip_preserves_existing_short_identity_and_index(): void
    {
        $task = $this->createTask(RFQ::class, (string) Str::ulid());
        $migration = require base_path(
            'database/migrations/2026_09_10_000001_widen_taskable_type_on_tasks_table.php'
        );

        $migration->down();

        $this->assertSame(50, $this->taskableTypeWidth());
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'taskable_type' => RFQ::class,
        ]);

        $migration->up();

        $this->assertSame(255, $this->taskableTypeWidth());
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'taskable_type' => RFQ::class,
        ]);
        $this->assertTrue($this->hasTaskableCompositeIndex());
    }

    public function test_rollback_fails_closed_without_truncating_long_identity(): void
    {
        $task = $this->createTask(PurchaseRequest::class, $this->purchaseRequest->id);
        $migration = require base_path(
            'database/migrations/2026_09_10_000001_widen_taskable_type_on_tasks_table.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback must reject a stored polymorphic identity longer than 50 characters.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stored polymorphic identity exceeds 50 characters', $exception->getMessage());
        }

        $this->assertSame(255, $this->taskableTypeWidth());
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'taskable_type' => PurchaseRequest::class,
        ]);
    }

    private function createTask(string $taskableType, string $taskableId): Task
    {
        return Task::create([
            'property_id' => $this->property->id,
            'task_type' => 'schema_proof',
            'source_module' => 'foundation',
            'taskable_type' => $taskableType,
            'taskable_id' => $taskableId,
            'title' => 'Task polymorphic identity width proof',
            'priority' => 'normal',
            'status' => TaskStatusEnum::Open,
        ]);
    }

    private function taskableTypeWidth(): int
    {
        return (int) DB::selectOne(<<<'SQL'
            SELECT character_maximum_length
              FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'tasks'
               AND column_name = 'taskable_type'
            SQL)->character_maximum_length;
    }

    private function hasTaskableCompositeIndex(): bool
    {
        return collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'tasks'"))
            ->pluck('indexdef')
            ->contains(fn (string $definition): bool => str_contains(
                $definition,
                '(taskable_type, taskable_id)',
            ));
    }
}
