<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Services\InventoryReversalApprovalRequestService;
use Modules\Operations\Inventory\Exceptions\InventoryReversalApprovalRequestRejectedException;
use Modules\Operations\Inventory\Exceptions\InventoryReversalCandidateRejectedException;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalApprovalRequestIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalApprovalRequestResult;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Foundation\Approval\Models\ApprovalRequest;

class InventoryReversalApprovalRequestServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryReversalApprovalRequestService $service;
    private Property $property;
    private User $user;
    private InventoryItem $item;
    private InventoryLocation $location;
    private InventoryCategory $category;
    private PropertyBusinessDate $businessDate;
    private FinancialPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryReversalApprovalRequestService::class);
        $this->property = Property::first();
        $this->user = User::first();
        $this->actingAs($this->user);

        $this->property->currency = 'USD';
        $this->property->save();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->businessDate = PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status'    => PropertyBusinessDateStatusEnum::Open,
                'is_open'   => true,
                'opened_at' => now(),
                'opened_by' => $this->user->id,
            ]
        );

        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            [
                'status'     => FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date'   => now()->endOfMonth(),
            ]
        );

        $this->category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name'        => 'General',
        ]);

        $this->item = InventoryItem::create([
            'property_id'           => $this->property->id,
            'category_id'           => $this->category->id,
            'sku'                   => 'ITM-REQ-999',
            'name'                  => 'Request Reversal Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Request Reversal Warehouse',
            'type'        => 'internal',
        ]);
    }

    private function seedWorkflow(): void
    {
        $txMorph = (new InventoryTransaction())->getMorphClass();

        DB::table('approval_workflows')->insertOrIgnore([
            'id' => 'exec-reversal-wf',
            'property_id' => $this->property->id,
            'name' => 'Inventory Reversal Workflow',
            'approvable_type' => $txMorph,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('approval_steps')->insertOrIgnore([
            'id' => 'exec-reversal-step',
            'workflow_id' => 'exec-reversal-wf',
            'sequence' => 1,
            'name' => 'Manager Approval',
            'required_approvals' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTransaction(
        TransactionTypeEnum $type,
        int $valuationSeq = 1,
        string $quantityChange = '5.0000',
        string $unitCost = '10.0000',
        string $totalCost = '50.0000'
    ): InventoryTransaction {
        $tx = new InventoryTransaction();
        $tx->id = (string) Str::ulid();
        $tx->property_id = $this->property->id;
        $tx->item_id = $this->item->id;
        $tx->location_id = $this->location->id;
        $tx->transaction_type = $type;
        $tx->quantity_before = '10.0000';
        $tx->quantity_change = $quantityChange;
        $tx->quantity_after = '15.0000';
        $tx->unit_cost = $unitCost;
        $tx->total_cost = $totalCost;
        $tx->posted_at = now();
        $tx->business_date = now()->toDateString();
        $tx->occurred_at = now();
        $tx->currency_code = 'USD';
        $tx->financial_period_id = $this->period->id;
        $tx->valuation_scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        $tx->valuation_sequence = $valuationSeq;
        $tx->save();

        return $tx;
    }

    public function test_purchase_receipt_reversal_approval_request_succeeds_once(): void
    {
        $this->seedWorkflow();
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $intent = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            reversalReason: 'Wrong receipt',
            idempotencyKey: 'req-idem-1'
        );

        $result = $this->service->request($intent);

        $this->assertEquals('created', $result->outcome);
        $this->assertEquals($tx->id, $result->originalTransaction->id);
        $this->assertEquals('Pending', $result->approvalRequest->status);
        $this->assertEquals($tx->id, $result->approvalRequest->approvable_id);
        $this->assertEquals('Wrong receipt', $result->approvalRequest->notes['reversal_reason']);

        // Test replay logic
        $replayResult = $this->service->request($intent);
        $this->assertEquals('replayed', $replayResult->outcome);
        $this->assertEquals($result->approvalRequest->id, $replayResult->approvalRequest->id);

        $this->assertEquals(1, ApprovalRequest::count());
    }

    public function test_issue_reversal_approval_request_succeeds(): void
    {
        $this->seedWorkflow();
        $tx = $this->createTransaction(TransactionTypeEnum::Issue, 1, '-5.0000', '10.0000', '-50.0000');

        $intent = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            reversalReason: 'Wrong issue',
            idempotencyKey: 'req-idem-2'
        );

        $result = $this->service->request($intent);

        $this->assertEquals('created', $result->outcome);
        $this->assertEquals('Pending', $result->approvalRequest->status);
    }

    public function test_reused_key_with_conflict_fails(): void
    {
        $this->seedWorkflow();
        $tx1 = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');
        $tx2 = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '2.0000', '10.0000', '20.0000');

        $intent1 = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $tx1->id,
            actorId: $this->user->id,
            reversalReason: 'Reason 1',
            idempotencyKey: 'req-idem-conflict'
        );

        $this->service->request($intent1);

        $intent2 = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $tx2->id,
            actorId: $this->user->id,
            reversalReason: 'Reason 2',
            idempotencyKey: 'req-idem-conflict'
        );

        $this->expectException(InventoryReversalApprovalRequestRejectedException::class);
        $this->expectExceptionMessage('Idempotency key is in use by a different request.');

        $this->service->request($intent2);
    }

    public function test_missing_workflow_fails(): void
    {
        // No workflow seeded
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $intent = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            reversalReason: 'No workflow',
            idempotencyKey: 'req-idem-missing-wf'
        );

        $this->expectException(InventoryReversalApprovalRequestRejectedException::class);
        $this->expectExceptionMessage('No active reversal approval workflow found for this property.');

        $this->service->request($intent);
    }

    public function test_candidate_guard_rejections_prevent_creation(): void
    {
        $this->seedWorkflow();
        // Create reversal type (which is ineligible as original candidate)
        $tx = $this->createTransaction(TransactionTypeEnum::Reversal, 1, '-5.0000', '10.0000', '-50.0000');

        $intent = new InventoryReversalApprovalRequestIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            reversalReason: 'Rejected candidate',
            idempotencyKey: 'req-idem-candidate-err'
        );

        $this->expectException(InventoryReversalCandidateRejectedException::class);
        $this->service->request($intent);
    }
}
