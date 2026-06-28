<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Services\InventoryReversalExecutionService;
use Modules\Operations\Inventory\Exceptions\InventoryReversalExecutionRejectedException;
use Modules\Operations\Inventory\Exceptions\InventoryReversalPostingRejectedException;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalExecutionIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalExecutionResult;
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

class InventoryReversalExecutionServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryReversalExecutionService $service;
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

        $this->service = app(InventoryReversalExecutionService::class);
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
            'sku'                   => 'ITM-EXEC-999',
            'name'                  => 'Execution Reversal Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Execution Reversal Warehouse',
            'type'        => 'internal',
        ]);
    }

    private function seedGroup(string $status = 'enrolled'): string
    {
        $id = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedSnapshot(string $groupId): string
    {
        $id = (string) Str::ulid();
        $valuationScope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        DB::table('cost_authority_enrollment_scope_snapshots')->insert([
            'id' => $id,
            'enrollment_group_id' => $groupId,
            'location_id' => $this->location->id,
            'valuation_scope' => $valuationScope,
            'opening_quantity' => '10.0000',
            'opening_carrying_value' => '100.0000',
            'currency_code' => 'USD',
            'business_date' => now()->toDateString(),
            'financial_period_id' => $this->period->id,
            'evidence_timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedState(string $groupId, string $snapshotId, string $qty = '10.0000', string $val = '100.0000', ?int $lastSeq = null, ?string $lastDate = null): void
    {
        $valuationScope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        DB::table('cost_avco_states')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'location_id' => $this->location->id,
            'item_id' => $this->item->id,
            'valuation_scope' => $valuationScope,
            'on_hand_quantity' => $qty,
            'carrying_value' => $val,
            'weighted_average_unit_cost' => '10.0000',
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => $lastSeq,
            'last_valuation_business_date' => $lastDate,
            'enrollment_group_id' => $groupId,
            'enrollment_scope_snapshot_id' => $snapshotId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedStock(string $qty = '10.0000'): void
    {
        DB::table('inventory_stocks')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'physical_quantity' => $qty,
            'reserved_quantity' => '0.0000',
            'status' => 'in_stock',
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

    private function seedApproval(
        string $id,
        string $status,
        string $approvableId,
        string $approvableType
    ): void {
        DB::table('approval_workflows')->insertOrIgnore([
            'id' => 'mock-wf-id',
            'property_id' => $this->property->id,
            'name' => 'Mock Reversal Approval Workflow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('approval_requests')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'approvable_type' => $approvableType,
            'approvable_id' => $approvableId,
            'workflow_id' => 'mock-wf-id',
            'requester_id' => $this->user->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_valid_approval_reversal_succeeds_once(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Approved', $tx->id, $txMorph);

        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            approvalReference: $approvalId,
            reversalReason: 'Mistake',
            idempotencyKey: 'exec-idem-1'
        );

        $result = $this->service->execute($intent);

        $this->assertEquals('posted', $result->outcome);
        $this->assertEquals($tx->id, $result->originalTransaction->id);
        $this->assertEquals(TransactionTypeEnum::Reversal, $result->reversalTransaction->transaction_type);

        // Verify replay returns identical result
        $replayResult = $this->service->execute($intent);
        $this->assertEquals('replayed', $replayResult->outcome);
        $this->assertEquals($result->reversalTransaction->id, $replayResult->reversalTransaction->id);

        // Assert no duplicate rows created
        $this->assertEquals(2, InventoryTransaction::count()); // original + 1 reversal
    }

    public function test_idempotency_key_reused_with_conflict_fails(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx1 = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');
        $tx2 = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '2.0000', '10.0000', '20.0000');

        $approvalId1 = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId1, 'Approved', $tx1->id, $txMorph);

        $intent1 = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx1->id,
            actorId: $this->user->id,
            approvalReference: $approvalId1,
            reversalReason: 'Reason 1',
            idempotencyKey: 'exec-idem-conflict'
        );

        $this->service->execute($intent1);

        $intent2 = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx2->id,
            actorId: $this->user->id,
            approvalReference: 'some-other-approval',
            reversalReason: 'Reason 2',
            idempotencyKey: 'exec-idem-conflict'
        );

        $this->expectException(InventoryReversalExecutionRejectedException::class);
        $this->expectExceptionMessage('Idempotency key is in use by a different request.');

        $this->service->execute($intent2);
    }

    public function test_approval_not_found_fails(): void
    {
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            approvalReference: (string) Str::ulid(), // random/missing
            reversalReason: 'Reason',
            idempotencyKey: 'exec-idem-missing-app'
        );

        $this->expectException(InventoryReversalExecutionRejectedException::class);
        $this->expectExceptionMessage('Approval request not found.');

        $this->service->execute($intent);
    }

    public function test_non_final_approval_fails(): void
    {
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Pending', $tx->id, $txMorph);

        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            approvalReference: $approvalId,
            reversalReason: 'Reason',
            idempotencyKey: 'exec-idem-non-final'
        );

        $this->expectException(InventoryReversalExecutionRejectedException::class);
        $this->expectExceptionMessage('Approval is not finally approved.');

        $this->service->execute($intent);
    }

    public function test_rejected_approval_fails(): void
    {
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Rejected', $tx->id, $txMorph);

        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            approvalReference: $approvalId,
            reversalReason: 'Reason',
            idempotencyKey: 'exec-idem-rejected'
        );

        $this->expectException(InventoryReversalExecutionRejectedException::class);
        $this->expectExceptionMessage('Approval is not executable.');

        $this->service->execute($intent);
    }

    public function test_unrelated_approval_fails(): void
    {
        $tx1 = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');
        $tx2 = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '2.0000', '10.0000', '20.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        // approval is bound to tx1
        $this->seedApproval($approvalId, 'Approved', $tx1->id, $txMorph);

        // request reverses tx2, but uses approval bound to tx1
        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx2->id,
            actorId: $this->user->id,
            approvalReference: $approvalId,
            reversalReason: 'Reason',
            idempotencyKey: 'exec-idem-unrelated'
        );

        $this->expectException(InventoryReversalExecutionRejectedException::class);
        $this->expectExceptionMessage('Approval is not applicable to the original transaction.');

        $this->service->execute($intent);
    }

    public function test_posting_service_rejection_propagates(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        // seed with sequence 2
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 2, now()->toDateString());
        $this->seedStock('10.0000');

        // transaction sequence is 1, which will trigger blocker rejection (since last seq is 2)
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Approved', $tx->id, $txMorph);

        $intent = new InventoryReversalExecutionIntent(
            originalTransactionId: $tx->id,
            actorId: $this->user->id,
            approvalReference: $approvalId,
            reversalReason: 'Should fail',
            idempotencyKey: 'exec-idem-fail-posting'
        );

        $this->expectException(InventoryReversalPostingRejectedException::class);
        $this->expectExceptionMessage('A later controlled movement exists in this valuation scope.');

        $this->service->execute($intent);
    }
}
