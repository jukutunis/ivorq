<?php

namespace Tests\Postgres\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\PostgresTestCase;

class PurchaseOrderRejectedStatusTest extends PostgresTestCase
{
    use CreatesFoundationData, RefreshDatabase;

    private PurchaseOrder $purchaseOrder;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $property = $this->createProperty($this->createCompany());
        $this->actor = $this->createUser($property);
        $this->actingAs($this->actor);
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $department = $this->createDepartment($property);
        $request = PurchaseRequest::create([
            'property_id' => $property->id,
            'department_id' => $department->id,
            'requester_id' => $this->actor->id,
            'request_no' => 'PR-REJECTED-STATUS',
            'required_date' => now()->addWeek(),
            'status' => 'APPROVED',
        ]);
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'REJECTED-STATUS',
            'name' => 'Purchase Order status proof',
        ]);
        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'REJECTED-STATUS',
            'name' => 'Purchase Order status proof',
        ]);
        $this->purchaseOrder = PurchaseOrder::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $request->id,
            'po_no' => 'PO-REJECTED-STATUS',
            'issue_date' => now(),
            'expected_delivery_date' => now()->addWeek(),
            'created_by' => $this->actor->id,
            'status' => PurchaseOrderStatusEnum::PendingReview,
        ]);
    }

    public static function persistedStatuses(): array
    {
        return [
            'Draft' => [PurchaseOrderStatusEnum::Draft, 'DRAFT'],
            'PendingReview' => [PurchaseOrderStatusEnum::PendingReview, 'PENDING_REVIEW'],
            'Approved' => [PurchaseOrderStatusEnum::Approved, 'APPROVED'],
            'Rejected' => [PurchaseOrderStatusEnum::Rejected, 'REJECTED'],
            'Issued' => [PurchaseOrderStatusEnum::Issued, 'ISSUED'],
            'PartiallyReceived' => [PurchaseOrderStatusEnum::PartiallyReceived, 'PARTIALLY_RECEIVED'],
            'FullyReceived' => [PurchaseOrderStatusEnum::FullyReceived, 'FULLY_RECEIVED'],
            'Closed' => [PurchaseOrderStatusEnum::Closed, 'CLOSED'],
            'Cancelled' => [PurchaseOrderStatusEnum::Cancelled, 'CANCELLED'],
        ];
    }

    #[DataProvider('persistedStatuses')]
    public function test_status_values_persist_and_cast_without_changing_existing_values(
        PurchaseOrderStatusEnum $status,
        string $persistedValue,
    ): void {
        $this->assertSame($persistedValue, $status->value);
        $this->assertSame($status, PurchaseOrderStatusEnum::from($persistedValue));

        $this->purchaseOrder->update(['status' => $status]);

        $this->assertSame($persistedValue, DB::table('purchase_orders')->where('id', $this->purchaseOrder->id)->value('status'));
        $this->assertSame($status, $this->purchaseOrder->fresh()->status);
        $this->assertNotSame(PurchaseOrderStatusEnum::Rejected, PurchaseOrderStatusEnum::Cancelled);
    }

    public function test_mark_as_rejected_persists_exact_reason_and_enum_cast(): void
    {
        $reason = 'Rejected: quoted delivery date does not meet the required date.';
        $purchaseRequest = $this->purchaseOrder->purchaseRequest->getAttributes();

        $this->purchaseOrder->markAsRejected($reason);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $this->purchaseOrder->id,
            'status' => 'REJECTED',
            'remarks' => $reason,
        ]);
        $reloaded = $this->purchaseOrder->fresh();
        $this->assertSame(PurchaseOrderStatusEnum::Rejected, $reloaded->status);
        $this->assertSame($reason, $reloaded->remarks);
        $this->assertSame($purchaseRequest, $reloaded->purchaseRequest->getAttributes());
    }

    public function test_approval_engine_rejection_persists_rejected_purchase_order(): void
    {
        $workflow = ApprovalWorkflow::create([
            'property_id' => $this->purchaseOrder->property_id,
            'approvable_type' => PurchaseOrder::class,
            'name' => 'Purchase Order rejection proof',
            'is_active' => true,
        ]);
        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Review',
            'required_approvals' => 1,
        ]);
        $engine = app(ApprovalEngineService::class);
        $request = $engine->submitForApproval($this->purchaseOrder, $this->actor->id);
        $reason = 'Purchase Order rejected by reviewer.';
        $purchaseRequest = $this->purchaseOrder->purchaseRequest->getAttributes();

        $engine->reject($request, $this->actor->id, $reason);

        $this->assertSame('Rejected', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);
        $this->assertSame(PurchaseOrderStatusEnum::Rejected, $this->purchaseOrder->fresh()->status);
        $this->assertDatabaseHas('purchase_orders', ['id' => $this->purchaseOrder->id, 'status' => 'REJECTED']);
        $this->assertDatabaseHas('approval_actions', [
            'approval_request_id' => $request->id,
            'action_type' => 'reject',
            'notes' => $reason,
            'user_id' => $this->actor->id,
        ]);
        // The existing listener repeats markAsRejected() without forwarding notes.
        // Engine rejection notes remain on the Approval action; direct model remarks are proved separately.
        $this->assertNull($this->purchaseOrder->fresh()->remarks);
        $this->assertSame($purchaseRequest, $this->purchaseOrder->fresh()->purchaseRequest->getAttributes());
    }
}
