<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Services\InventoryReversalPostingService;
use Modules\Operations\Inventory\Exceptions\InventoryReversalPostingRejectedException;
use Modules\Operations\Inventory\Exceptions\InventoryReversalCandidateRejectedException;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingResult;
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

class InventoryReversalPostingServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private InventoryReversalPostingService $service;
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

        $this->service = app(InventoryReversalPostingService::class);
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
            'sku'                   => 'ITM-REV-999',
            'name'                  => 'Reversal Posting Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'Reversal Posting Warehouse',
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

    public function test_purchase_receipt_reversal_succeeds(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $intent = new InventoryReversalPostingIntent(
            originalTransactionId: $tx->id,
            idempotencyKey: 'idem-post-1',
            actorId: $this->user->id,
            approvalReference: 'APP-123',
            reversalReason: 'Duplicate entry'
        );

        $result = $this->service->post($intent);

        $this->assertEquals($tx->id, $result->originalTransaction->id);
        $this->assertEquals(TransactionTypeEnum::Reversal, $result->reversalTransaction->transaction_type);
        $this->assertEquals('-5.0000', (string) $result->reversalTransaction->quantity_change);
        $this->assertEquals('-50.00', (string) $result->reversalTransaction->total_cost);
        $this->assertEquals('10.0000', (string) $result->reversalTransaction->unit_cost);
        $this->assertEquals($tx->id, $result->reversalTransaction->reverses_inventory_transaction_id);

        $freshState = DB::table('cost_avco_states')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals('5.0000', (string) $freshState->on_hand_quantity);
        $this->assertEquals('50.0000', (string) $freshState->carrying_value);

        // Verify physical stock was reduced by exactly original quantity (+5.0000 original -> -5.0000 delta)
        $stock = DB::table('inventory_stocks')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals('5.0000', (string) $stock->physical_quantity);

        $this->assertNotNull($result->costLedgerEntry);
        $this->assertEquals('reversal', $result->costLedgerEntry->entry_type);

        $this->assertNotNull($result->auditLog);
        $this->assertEquals('reversal', $result->auditLog->event);
    }

    public function test_issue_reversal_succeeds(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::Issue, 1, '-5.0000', '10.0000', '-50.0000');

        $intent = new InventoryReversalPostingIntent(
            originalTransactionId: $tx->id,
            idempotencyKey: 'idem-post-2',
            actorId: $this->user->id,
            approvalReference: 'APP-456',
            reversalReason: 'Wrong issue'
        );

        $result = $this->service->post($intent);

        $this->assertEquals('5.0000', (string) $result->reversalTransaction->quantity_change);
        $this->assertEquals('50.00', (string) $result->reversalTransaction->total_cost);
        $this->assertEquals('10.0000', (string) $result->reversalTransaction->unit_cost);

        $freshState = DB::table('cost_avco_states')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals('15.0000', (string) $freshState->on_hand_quantity);
        $this->assertEquals('150.0000', (string) $freshState->carrying_value);

        // Verify physical stock was increased by exactly absolute original quantity (-5.0000 original -> +5.0000 delta)
        $stock = DB::table('inventory_stocks')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals('15.0000', (string) $stock->physical_quantity);
    }

    public function test_later_controlled_movement_blocks(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 2, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');
        $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 2, '2.0000', '10.0000', '20.0000');

        $intent = new InventoryReversalPostingIntent(
            originalTransactionId: $tx->id,
            idempotencyKey: 'idem-post-3',
            actorId: $this->user->id,
            approvalReference: 'APP-789',
            reversalReason: 'Later blocker test'
        );

        $this->expectException(InventoryReversalPostingRejectedException::class);
        $this->expectExceptionMessage('A later controlled movement exists in this valuation scope.');

        $this->service->post($intent);
    }

    public function test_closed_business_date_blocks(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $this->businessDate->status = PropertyBusinessDateStatusEnum::Closed;
        $this->businessDate->is_open = false;
        $this->businessDate->save();

        $intent = new InventoryReversalPostingIntent(
            originalTransactionId: $tx->id,
            idempotencyKey: 'idem-post-4',
            actorId: $this->user->id,
            approvalReference: 'APP-999',
            reversalReason: 'Closed date test'
        );

        $this->expectException(InventoryReversalPostingRejectedException::class);
        $this->expectExceptionMessage('No open business date found for property.');

        $this->service->post($intent);
    }

    public function test_closed_financial_period_blocks(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $this->period->status = FinancialPeriodStatusEnum::Closed;
        $this->period->save();

        $intent = new InventoryReversalPostingIntent(
            originalTransactionId: $tx->id,
            idempotencyKey: 'idem-post-5',
            actorId: $this->user->id,
            approvalReference: 'APP-999',
            reversalReason: 'Closed period test'
        );

        $this->expectException(InventoryReversalPostingRejectedException::class);
        $this->expectExceptionMessage('Financial period is closed or missing.');

        $this->service->post($intent);
    }

    public function test_forced_failure_rolls_back_atomic_writes(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $intent = new InventoryReversalPostingIntent(
            originalTransactionId: $tx->id,
            idempotencyKey: 'idem-post-6',
            actorId: $this->user->id,
            approvalReference: 'APP-999',
            reversalReason: 'Rollback test'
        );

        \Modules\Foundation\Audit\Models\AuditLog::creating(function () {
            throw new \RuntimeException('Forced transaction rollback.');
        });

        try {
            $this->service->post($intent);
            $this->fail('Expected transaction to fail and rollback.');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Forced transaction rollback.', $e->getMessage());
        }

        $this->assertEquals(1, InventoryTransaction::count());
        $this->assertDatabaseCount('cost_ledger_entries', 0);

        $freshState = DB::table('cost_avco_states')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals('10.0000', (string) $freshState->on_hand_quantity);

        // Verify physical stock was rolled back as well
        $stock = DB::table('inventory_stocks')
            ->where('property_id', $this->property->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals('10.0000', (string) $stock->physical_quantity);
    }
}
