<?php

namespace Tests\Postgres\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Notification\Models\AppNotification;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Models\Task;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Purchasing\Listeners\PurchasingApprovalListener;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Shared\Services\CurrentPropertyService;
use stdClass;
use Tests\PostgresTestCase;

class PurchasingApprovalListenerIsolationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private PurchasingApprovalListener $listener;

    private Property $property;

    private User $actor;

    private InventoryTransaction $transaction;

    private ApprovalRequest $inventoryApprovalRequest;

    private PurchaseRequest $purchaseRequest;

    private PurchaseOrder $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = app(PurchasingApprovalListener::class);
        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        $this->actor->properties()->syncWithoutDetaching([
            $this->property->id => ['status' => 'active'],
        ]);
        $this->actingAs($this->actor);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->createPurchasingDocuments();
        $this->createInventoryApprovalRequest();
    }

    public function test_requested_foreign_approvable_is_ignored(): void
    {
        $this->assertForeignEventHasNoPurchasingSideEffects(
            fn () => event(new ApprovalRequested($this->inventoryApprovalRequest)),
        );
    }

    public function test_approved_foreign_approvable_is_ignored(): void
    {
        $this->assertForeignEventHasNoPurchasingSideEffects(
            fn () => event(new ApprovalApproved($this->inventoryApprovalRequest)),
        );
    }

    public function test_rejected_foreign_approvable_is_ignored(): void
    {
        $this->assertForeignEventHasNoPurchasingSideEffects(
            fn () => event(new ApprovalRejected($this->inventoryApprovalRequest)),
        );
    }

    public function test_cancelled_foreign_approvable_is_ignored(): void
    {
        $this->assertForeignEventHasNoPurchasingSideEffects(
            fn () => event(new ApprovalCancelled($this->inventoryApprovalRequest)),
        );
    }

    public function test_classifier_recognizes_only_purchasing_models(): void
    {
        $classifier = new ReflectionMethod($this->listener, 'isPurchasingDocument');

        $this->assertTrue($classifier->invoke($this->listener, new PurchaseRequest));
        $this->assertTrue($classifier->invoke($this->listener, new PurchaseOrder));
        $this->assertFalse($classifier->invoke($this->listener, $this->transaction));
        $this->assertFalse($classifier->invoke($this->listener, new ReceivingDocument));
        $this->assertFalse($classifier->invoke($this->listener, $this->actor));
        $this->assertFalse($classifier->invoke($this->listener, new stdClass));
        $this->assertFalse($classifier->invoke($this->listener, null));
    }

    public function test_handlers_ignore_null_and_unexpected_objects(): void
    {
        foreach ([null, new stdClass, new ReceivingDocument] as $target) {
            $this->inventoryApprovalRequest->setRelation('approvable', $target);

            $this->assertForeignEventHasNoPurchasingSideEffects(function (): void {
                $this->listener->handleRequested(new ApprovalRequested($this->inventoryApprovalRequest));
                $this->listener->handleApproved(new ApprovalApproved($this->inventoryApprovalRequest));
                $this->listener->handleRejected(new ApprovalRejected($this->inventoryApprovalRequest));
                $this->listener->handleCancelled(new ApprovalCancelled($this->inventoryApprovalRequest));
            });
        }
    }

    public static function purchasingDocuments(): array
    {
        return [
            'Purchase Request' => ['purchaseRequest'],
            'Purchase Order' => ['purchaseOrder'],
        ];
    }

    #[DataProvider('purchasingDocuments')]
    public function test_real_approval_engine_owns_approved_transition_and_listener_preserves_reactions(
        string $documentProperty,
    ): void {
        $document = $this->{$documentProperty};
        $this->createWorkflowFor($document);
        $engine = app(ApprovalEngineService::class);
        $before = $this->purchasingEffectCounts();
        $notes = 'Approved by purchasing isolation proof.';

        $request = $engine->submitForApproval($document, $this->actor->id);
        $engine->approve($request, $this->actor->id, $notes);

        $this->assertSame('Approved', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);
        $this->assertSame('APPROVED', $document->fresh()->status->value);
        $this->assertDatabaseHas('approval_actions', [
            'approval_request_id' => $request->id,
            'action_type' => 'approve',
            'notes' => $notes,
            'user_id' => $this->actor->id,
        ]);
        $this->assertSame($before['tasks'] + 2, $this->purchasingTaskCount());
        $this->assertSame($before['notifications'] + 2, $this->purchasingNotificationCount());
        $this->assertSame(2, Task::withoutGlobalScopes()
            ->where('source_module', 'purchasing')
            ->where('taskable_type', $document::class)
            ->where('taskable_id', $document->id)
            ->count());
        $this->assertDatabaseHas('app_notifications', [
            'property_id' => $this->property->id,
            'user_id' => $this->actor->id,
            'type' => 'purchasing.approval_required',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'property_id' => $this->property->id,
            'user_id' => $this->actor->id,
            'type' => 'purchasing.approved',
        ]);
    }

    #[DataProvider('purchasingDocuments')]
    public function test_real_approval_engine_owns_rejected_transition_and_preserves_reason(
        string $documentProperty,
    ): void {
        $document = $this->{$documentProperty};
        $this->createWorkflowFor($document);
        $engine = app(ApprovalEngineService::class);
        $before = $this->purchasingEffectCounts();
        $reason = 'Rejected by purchasing isolation proof.';

        $request = $engine->submitForApproval($document, $this->actor->id);
        $engine->reject($request, $this->actor->id, $reason);

        $this->assertSame('Rejected', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);
        $this->assertSame('REJECTED', $document->fresh()->status->value);
        $this->assertDatabaseHas('approval_actions', [
            'approval_request_id' => $request->id,
            'action_type' => 'reject',
            'notes' => $reason,
            'user_id' => $this->actor->id,
        ]);
        $this->assertSame($before['tasks'] + 1, $this->purchasingTaskCount());
        $this->assertSame($before['notifications'] + 1, $this->purchasingNotificationCount());
        $this->assertDatabaseMissing('app_notifications', [
            'property_id' => $this->property->id,
            'user_id' => $this->actor->id,
            'type' => 'purchasing.approved',
        ]);

        if ($document instanceof PurchaseOrder) {
            $this->assertSame($reason, $document->fresh()->remarks);
        } else {
            $this->assertSame($reason, $document->fresh()->rejection_reason);
        }
    }

    #[DataProvider('purchasingDocuments')]
    public function test_real_cancellation_does_not_reject_or_mutate_purchasing_document(
        string $documentProperty,
    ): void {
        $document = $this->{$documentProperty};
        $documentBefore = $document->fresh()->getAttributes();
        $this->createWorkflowFor($document);
        $engine = app(ApprovalEngineService::class);
        $reason = '  Purchasing request withdrawn.  ';

        $request = $engine->submitForApproval($document, $this->actor->id);
        $tasksAfterSubmit = $this->purchasingTaskCount();
        $notificationsAfterSubmit = $this->purchasingNotificationCount();

        $engine->cancel($request, $this->actor->id, $reason);

        $cancelled = $request->fresh();
        $this->assertSame('Cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->completed_at);
        $this->assertNull($cancelled->current_step_id);
        $this->assertSame($documentBefore, $document->fresh()->getAttributes());
        $this->assertNotSame('REJECTED', $document->fresh()->status->value);
        $this->assertDatabaseHas('approval_actions', [
            'approval_request_id' => $request->id,
            'user_id' => $this->actor->id,
            'action_type' => 'cancel',
            'notes' => trim($reason),
        ]);
        $this->assertSame($tasksAfterSubmit, $this->purchasingTaskCount());
        $this->assertSame($notificationsAfterSubmit, $this->purchasingNotificationCount());
        $this->assertSame(1, Task::withoutGlobalScopes()
            ->where('source_module', 'purchasing')
            ->where('taskable_type', $document::class)
            ->where('taskable_id', $document->id)
            ->count());
        $this->assertSame(1, AppNotification::withoutGlobalScopes()
            ->where('user_id', $this->actor->id)
            ->where('type', 'ApprovalCancelled')
            ->count());
    }

    private function assertForeignEventHasNoPurchasingSideEffects(callable $invoke): void
    {
        $before = $this->purchasingEffectCounts();
        $transactionAttributes = $this->transaction->fresh()->getAttributes();
        $requestAttributes = $this->purchaseRequest->fresh()->getAttributes();
        $orderAttributes = $this->purchaseOrder->fresh()->getAttributes();
        $approvalAttributes = $this->inventoryApprovalRequest->fresh()->getAttributes();

        $invoke();

        $this->assertSame($before['tasks'], $this->purchasingTaskCount());
        $this->assertSame($before['notifications'], $this->purchasingNotificationCount());
        $this->assertSame($transactionAttributes, $this->transaction->fresh()->getAttributes());
        $this->assertSame($requestAttributes, $this->purchaseRequest->fresh()->getAttributes());
        $this->assertSame($orderAttributes, $this->purchaseOrder->fresh()->getAttributes());
        $this->assertSame($approvalAttributes, $this->inventoryApprovalRequest->fresh()->getAttributes());
        $this->assertSame(1, DB::table('approval_requests')
            ->where('id', $this->inventoryApprovalRequest->id)
            ->count());
    }

    private function createPurchasingDocuments(): void
    {
        $department = Department::create([
            'property_id' => $this->property->id,
            'name' => 'Purchasing isolation',
            'code' => 'PI'.Str::upper(Str::random(6)),
        ]);
        $this->purchaseRequest = PurchaseRequest::create([
            'property_id' => $this->property->id,
            'request_no' => 'PR-ISO-'.Str::upper(Str::random(8)),
            'department_id' => $department->id,
            'requester_id' => $this->actor->id,
            'required_date' => now()->addWeek(),
            'estimated_total' => 100,
            'status' => 'DRAFT',
        ]);
        $category = VendorCategory::create([
            'property_id' => $this->property->id,
            'category_code' => 'PI'.Str::upper(Str::random(6)),
            'name' => 'Purchasing isolation',
            'is_active' => true,
        ]);
        $vendor = Vendor::create([
            'property_id' => $this->property->id,
            'company_id' => $this->property->company_id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'PIV'.Str::upper(Str::random(6)),
            'name' => 'Purchasing isolation vendor',
            'is_active' => true,
            'is_approved' => true,
        ]);
        $this->purchaseOrder = PurchaseOrder::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $this->purchaseRequest->id,
            'po_no' => 'PO-ISO-'.Str::upper(Str::random(8)),
            'issue_date' => now(),
            'expected_delivery_date' => now()->addWeek(),
            'status' => 'DRAFT',
            'created_by' => $this->actor->id,
        ]);
    }

    private function createInventoryApprovalRequest(): void
    {
        $category = InventoryCategory::create([
            'property_id' => $this->property->id,
            'name' => 'Purchasing isolation '.Str::random(8),
        ]);
        $item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'PUR-ISO-'.Str::upper(Str::random(8)),
            'name' => 'Purchasing isolation item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10,
            'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Purchasing isolation location '.Str::random(8),
            'type' => 'internal',
        ]);
        $period = FinancialPeriod::updateOrCreate(
            [
                'property_id' => $this->property->id,
                'period_year' => now()->year,
                'period_month' => now()->month,
            ],
            [
                'status' => FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth(),
            ],
        );
        $this->transaction = InventoryTransaction::create([
            'property_id' => $this->property->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'transaction_type' => TransactionTypeEnum::PurchaseReceipt,
            'quantity_before' => '0.0000',
            'quantity_change' => '1.0000',
            'quantity_after' => '1.0000',
            'unit_cost' => '10.0000',
            'total_cost' => '10.0000',
            'posted_at' => now(),
            'business_date' => now()->toDateString(),
            'occurred_at' => now(),
            'currency_code' => 'USD',
            'financial_period_id' => $period->id,
            'valuation_scope' => "property:{$this->property->id}:location:{$location->id}:item:{$item->id}",
            'valuation_sequence' => 1,
        ]);
        $workflow = ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'name' => 'Inventory reversal isolation '.Str::random(8),
            'approvable_type' => $this->transaction->getMorphClass(),
            'is_active' => true,
        ]);
        $this->inventoryApprovalRequest = ApprovalRequest::create([
            'property_id' => $this->property->id,
            'approvable_type' => $this->transaction->getMorphClass(),
            'approvable_id' => $this->transaction->id,
            'workflow_id' => $workflow->id,
            'requester_id' => $this->actor->id,
            'status' => 'Pending',
            'requested_at' => now(),
        ])->fresh();

        $this->assertInstanceOf(InventoryTransaction::class, $this->inventoryApprovalRequest->approvable);
    }

    private function createWorkflowFor(PurchaseRequest|PurchaseOrder $document): void
    {
        $workflow = ApprovalWorkflow::create([
            'property_id' => $this->property->id,
            'name' => 'Purchasing isolation workflow '.Str::random(8),
            'approvable_type' => $document::class,
            'is_active' => true,
        ]);
        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Review',
            'required_approvals' => 1,
        ]);
    }

    private function purchasingEffectCounts(): array
    {
        return [
            'tasks' => $this->purchasingTaskCount(),
            'notifications' => $this->purchasingNotificationCount(),
        ];
    }

    private function purchasingTaskCount(): int
    {
        return Task::withoutGlobalScopes()->where('source_module', 'purchasing')->count();
    }

    private function purchasingNotificationCount(): int
    {
        return AppNotification::withoutGlobalScopes()->where('type', 'like', 'purchasing.%')->count();
    }
}
