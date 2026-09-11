<?php

namespace Tests\Postgres\Foundation\Task;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Enums\TaskStatusEnum;
use Modules\Foundation\Task\Events\TaskCancelled;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\Task\Services\TaskService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\PostgresTestCase;
use Throwable;

class ApprovalTaskCancellationLifecycleTest extends PostgresTestCase
{
    use CreatesFoundationData, RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $requester;

    private User $approver;

    private ApprovalEngineService $approvalEngine;

    private TaskService $taskService;

    private PurchaseRequest $purchaseRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->createProperty($this->createCompany());
        $this->requester = $this->createUser($this->property);
        $this->approver = $this->createUser($this->property, 'general-manager');
        $this->actingAs($this->requester);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        $this->approvalEngine = app(ApprovalEngineService::class);
        $this->taskService = app(TaskService::class);
        $this->purchaseRequest = $this->createPurchaseRequest();
    }

    public function test_schema_adds_nullable_exact_request_identity_and_bounded_index_without_backfill(): void
    {
        $identityColumn = DB::selectOne(<<<'SQL'
            SELECT data_type, character_maximum_length, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'tasks'
              AND column_name = 'approval_request_id'
            SQL);
        $taskableColumn = DB::selectOne(<<<'SQL'
            SELECT character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'tasks'
              AND column_name = 'taskable_type'
            SQL);
        $index = DB::selectOne(<<<'SQL'
            SELECT indexdef
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename = 'tasks'
              AND indexname = 'tasks_approval_request_status_index'
            SQL);

        $this->assertNotNull($identityColumn);
        $this->assertSame('character', $identityColumn->data_type);
        $this->assertSame(26, $identityColumn->character_maximum_length);
        $this->assertSame('YES', $identityColumn->is_nullable);
        $this->assertNotNull($index);
        $this->assertStringContainsString('(approval_request_id, status)', $index->indexdef);
        $this->assertSame(255, $taskableColumn->character_maximum_length);

        $legacyTask = $this->createTask(null, TaskStatusEnum::Open);

        $this->assertNull($legacyTask->fresh()->approval_request_id);

        $migration = file_get_contents(database_path(
            'migrations/2026_09_11_000001_add_approval_request_id_to_tasks_table.php'
        ));

        $this->assertStringNotContainsString('DB::', $migration);
        $this->assertStringNotContainsString('->update(', $migration);
    }

    public function test_task_service_cancels_only_all_active_exact_property_tasks_once(): void
    {
        $approvalRequestId = (string) Str::ulid();
        $otherRequestId = (string) Str::ulid();
        $otherProperty = $this->createProperty($this->createCompany());
        $activeStatuses = [
            TaskStatusEnum::Draft,
            TaskStatusEnum::Open,
            TaskStatusEnum::Assigned,
            TaskStatusEnum::InProgress,
            TaskStatusEnum::OnHold,
        ];
        $activeTasks = collect($activeStatuses)
            ->map(fn (TaskStatusEnum $status): Task => $this->createTask($approvalRequestId, $status));
        $terminalTasks = collect([
            TaskStatusEnum::Completed,
            TaskStatusEnum::Cancelled,
            TaskStatusEnum::Closed,
        ])->map(fn (TaskStatusEnum $status): Task => $this->createTask($approvalRequestId, $status));
        $terminalBefore = $terminalTasks->mapWithKeys(
            fn (Task $task): array => [$task->id => $this->persistedTaskAttributes($task)]
        );
        $legacyTask = $this->createTask(null, TaskStatusEnum::Open);
        $otherRequestTask = $this->createTask($otherRequestId, TaskStatusEnum::Open);
        $otherPropertyTask = $this->createTask(
            $approvalRequestId,
            TaskStatusEnum::Open,
            $otherProperty->id,
        );
        $unrelatedBefore = collect([$legacyTask, $otherRequestTask, $otherPropertyTask])
            ->mapWithKeys(fn (Task $task): array => [$task->id => $this->persistedTaskAttributes($task)]);
        $cancelledTaskIds = [];

        Event::listen(TaskCancelled::class, function (TaskCancelled $event) use (&$cancelledTaskIds): void {
            $cancelledTaskIds[] = $event->task->id;
        });

        $notificationCount = $this->taskCancelledNotificationCount();
        $cancelledCount = $this->taskService->cancelActiveApprovalTasks(
            $approvalRequestId,
            $this->property->id,
        );

        $this->assertSame(5, $cancelledCount);
        $this->assertEqualsCanonicalizing($activeTasks->pluck('id')->all(), $cancelledTaskIds);
        foreach ($activeTasks as $task) {
            $this->assertSame(TaskStatusEnum::Cancelled, $task->fresh()->status);
        }
        foreach ($terminalTasks as $task) {
            $this->assertSame($terminalBefore[$task->id], $task->fresh()->getAttributes());
        }
        foreach ([$legacyTask, $otherRequestTask, $otherPropertyTask] as $task) {
            $this->assertSame($unrelatedBefore[$task->id], Task::withoutGlobalScopes()->findOrFail($task->id)->getAttributes());
        }
        $this->assertSame($notificationCount + 5, $this->taskCancelledNotificationCount());

        $this->assertSame(0, $this->taskService->cancelActiveApprovalTasks(
            $approvalRequestId,
            $this->property->id,
        ));
        $this->assertCount(5, $cancelledTaskIds);
        $this->assertSame($notificationCount + 5, $this->taskCancelledNotificationCount());
    }

    public function test_multi_step_tasks_use_exact_request_identity_and_same_document_request_is_isolated(): void
    {
        $this->createWorkflow($this->purchaseRequest::class, 2, $this->approver);

        $requestA = $this->approvalEngine->submitForApproval($this->purchaseRequest, $this->requester->id);
        $this->approvalEngine->approve($requestA, $this->approver->id, 'Advance to step two.');
        $requestB = $this->approvalEngine->submitForApproval($this->purchaseRequest, $this->requester->id);
        $tasksA = $this->reviewTasksFor($requestA);
        $tasksB = $this->reviewTasksFor($requestB);
        $legacyTask = $this->createTask(null, TaskStatusEnum::Open);
        $tasksABefore = $tasksA->mapWithKeys(fn (Task $task): array => [$task->id => $task->getAttributes()]);
        $tasksBBefore = $tasksB->mapWithKeys(fn (Task $task): array => [$task->id => $task->getAttributes()]);
        $legacyBefore = $this->persistedTaskAttributes($legacyTask);
        $documentBefore = $this->purchaseRequest->fresh()->getAttributes();
        $cancelledTaskIds = [];

        Event::listen(TaskCancelled::class, function (TaskCancelled $event) use (&$cancelledTaskIds): void {
            $cancelledTaskIds[] = $event->task->id;
        });

        $this->assertCount(2, $tasksA);
        $this->assertCount(1, $tasksB);
        $this->assertTrue($tasksA->every(
            fn (Task $task): bool => $task->approval_request_id === $requestA->id
        ));
        $this->assertTrue($tasksB->every(
            fn (Task $task): bool => $task->approval_request_id === $requestB->id
        ));

        $this->approvalEngine->cancel($requestA, $this->requester->id, 'Exact request withdrawn.');

        $tasksAAfter = $this->reviewTasksFor($requestA);
        $this->assertEqualsCanonicalizing($tasksA->pluck('id')->all(), $tasksAAfter->pluck('id')->all());
        $this->assertEqualsCanonicalizing($tasksA->pluck('id')->all(), $cancelledTaskIds);
        foreach ($tasksAAfter as $task) {
            $this->assertSame(TaskStatusEnum::Cancelled, $task->status);
            $this->assertSame($tasksABefore[$task->id]['title'], $task->getRawOriginal('title'));
            $this->assertSame($tasksABefore[$task->id]['description'], $task->getRawOriginal('description'));
        }
        foreach ($tasksB as $task) {
            $this->assertSame($tasksBBefore[$task->id], $task->fresh()->getAttributes());
            $this->assertSame(TaskStatusEnum::Open, $task->fresh()->status);
        }
        $this->assertSame($legacyBefore, $legacyTask->fresh()->getAttributes());
        $this->assertSame($documentBefore, $this->purchaseRequest->fresh()->getAttributes());
        $this->assertSame('Pending', $requestB->fresh()->status);
    }

    public function test_purchasing_post_approval_task_has_no_approval_request_identity(): void
    {
        $this->createWorkflow($this->purchaseRequest::class, 1, $this->approver);
        $request = $this->approvalEngine->submitForApproval($this->purchaseRequest, $this->requester->id);

        $this->approvalEngine->approve($request, $this->approver->id, 'Final approval.');

        $postApprovalTask = Task::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('taskable_type', $this->purchaseRequest::class)
            ->where('taskable_id', $this->purchaseRequest->id)
            ->where('description', 'The document has been fully approved.')
            ->sole();

        $this->assertNull($postApprovalTask->approval_request_id);
        $this->assertSame(TaskStatusEnum::Open, $postApprovalTask->status);
    }

    public function test_receiving_review_task_identity_cancellation_preserves_assignment_and_document(): void
    {
        $receivingDocument = $this->createReceivingDocument();
        $this->createWorkflow($receivingDocument::class, 1, $this->approver);
        $request = $this->approvalEngine->submitForApproval($receivingDocument, $this->requester->id);
        $task = Task::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('approval_request_id', $request->id)
            ->sole();
        $assignmentBefore = $task->assignments()->sole()->getAttributes();
        $documentBefore = $receivingDocument->fresh()->getAttributes();
        $cancelledTaskIds = [];

        Event::listen(TaskCancelled::class, function (TaskCancelled $event) use (&$cancelledTaskIds): void {
            $cancelledTaskIds[] = $event->task->id;
        });

        $this->approvalEngine->cancel($request, $this->requester->id, 'Receiving review withdrawn.');

        $this->assertSame($request->id, $task->fresh()->approval_request_id);
        $this->assertSame(TaskStatusEnum::Cancelled, $task->fresh()->status);
        $this->assertSame($assignmentBefore, $task->assignments()->sole()->getAttributes());
        $this->assertSame($documentBefore, $receivingDocument->fresh()->getAttributes());
        $this->assertSame(ReceivingDocumentStatusEnum::Draft, $receivingDocument->fresh()->status);
        $this->assertSame([$task->id], $cancelledTaskIds);
        $this->assertSame(1, AppNotification::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('type', 'ApprovalCancelled')
            ->where('user_id', $this->requester->id)
            ->count());
        $this->assertSame(1, AppNotification::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('type', 'TaskCancelled')
            ->where('user_id', $this->requester->id)
            ->where('data->task_id', $task->id)
            ->count());
    }

    public function test_task_cleanup_failure_rolls_back_approval_action_request_and_task(): void
    {
        $this->createWorkflow($this->purchaseRequest::class, 1, $this->approver);
        $request = $this->approvalEngine->submitForApproval($this->purchaseRequest, $this->requester->id);
        $task = $this->reviewTasksFor($request)->sole();
        $requestBefore = $request->fresh()->getAttributes();
        $taskBefore = $task->getAttributes();
        $notificationCount = AppNotification::withoutGlobalScopes()->count();

        Event::listen(TaskCancelled::class, function (): void {
            throw new RuntimeException('Proven task cleanup failure.');
        });

        $thrown = null;

        try {
            $this->approvalEngine->cancel($request, $this->requester->id, 'Must roll back.');
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('Proven task cleanup failure.', $thrown->getMessage());
        $this->assertSame($requestBefore, $request->fresh()->getAttributes());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertSame(0, $request->actions()->where('action_type', 'cancel')->count());
        $this->assertSame($notificationCount, AppNotification::withoutGlobalScopes()->count());
    }

    private function createPurchaseRequest(): PurchaseRequest
    {
        $department = $this->createDepartment($this->property);

        return PurchaseRequest::create([
            'property_id' => $this->property->id,
            'request_no' => 'PR-TASK-CANCEL-'.Str::upper(Str::random(8)),
            'department_id' => $department->id,
            'requester_id' => $this->requester->id,
            'required_date' => now()->addWeek(),
            'estimated_total' => 100,
            'status' => 'DRAFT',
        ]);
    }

    private function createReceivingDocument(): ReceivingDocument
    {
        $category = VendorCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'TASK-CANCEL',
            'name' => 'Task cancellation vendor category',
        ]);
        $vendor = Vendor::create([
            'property_id' => $this->property->id,
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'TASK-CANCEL',
            'name' => 'Task cancellation vendor',
        ]);

        return ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'GRN-TASK-CANCEL',
            'status' => ReceivingDocumentStatusEnum::Draft,
            'remarks' => 'Receiving document remains unchanged.',
        ]);
    }

    private function createWorkflow(string $approvableType, int $steps, User $assignee): ApprovalWorkflow
    {
        $workflow = ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'name' => 'Task cancellation workflow '.Str::random(8),
            'approvable_type' => $approvableType,
            'is_active' => true,
        ]);

        foreach (range(1, $steps) as $sequence) {
            $step = ApprovalStep::create([
                'workflow_id' => $workflow->id,
                'sequence' => $sequence,
                'name' => "Review step {$sequence}",
                'required_approvals' => 1,
            ]);
            ApprovalStepAssignee::create([
                'step_id' => $step->id,
                'assignee_type' => 'USER',
                'user_id' => $assignee->id,
            ]);
        }

        return $workflow;
    }

    private function createTask(
        ?string $approvalRequestId,
        TaskStatusEnum $status,
        ?string $propertyId = null,
    ): Task {
        return Task::create([
            'property_id' => $propertyId ?? $this->property->id,
            'task_type' => 'Approval',
            'source_module' => 'purchasing',
            'taskable_type' => $this->purchaseRequest::class,
            'taskable_id' => $this->purchaseRequest->id,
            'approval_request_id' => $approvalRequestId,
            'title' => 'Approval review task '.Str::random(8),
            'description' => 'Durable task history.',
            'priority' => 'normal',
            'status' => $status->value,
        ]);
    }

    private function reviewTasksFor(ApprovalRequest $request)
    {
        return Task::withoutGlobalScopes()
            ->where('property_id', $request->property_id)
            ->where('approval_request_id', $request->id)
            ->where('description', 'Please review and approve this document.')
            ->orderBy('id')
            ->get();
    }

    private function taskCancelledNotificationCount(): int
    {
        return AppNotification::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('type', 'TaskCancelled')
            ->count();
    }

    private function persistedTaskAttributes(Task $task): array
    {
        return Task::withoutGlobalScopes()->findOrFail($task->id)->getAttributes();
    }
}
