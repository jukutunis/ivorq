<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Foundation\Authorization\Models\Permission;

class InventoryReversalOperationalHttpBoundaryTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

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

        $this->property = Property::first();
        $this->user = User::first();

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
            'sku'                   => 'ITM-HTTP-999',
            'name'                  => 'HTTP Reversal Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'HTTP Reversal Warehouse',
            'type'        => 'internal',
        ]);

        Permission::firstOrCreate(['name' => 'inventory.reversal.request', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.reversal.execute', 'guard_name' => 'web']);
    }

    private function seedWorkflow(): void
    {
        $txMorph = (new InventoryTransaction())->getMorphClass();

        DB::table('approval_workflows')->insertOrIgnore([
            'id' => 'http-reversal-wf',
            'property_id' => $this->property->id,
            'name' => 'Inventory Reversal Workflow',
            'approvable_type' => $txMorph,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('approval_steps')->insertOrIgnore([
            'id' => 'http-reversal-step',
            'workflow_id' => 'http-reversal-wf',
            'sequence' => 1,
            'name' => 'Manager Approval',
            'required_approvals' => 1,
            'created_at' => now(),
            'updated_at' => now(),
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
        string $approvableType,
        string $reason
    ): void {
        DB::table('approval_workflows')->insertOrIgnore([
            'id' => 'http-reversal-wf',
            'property_id' => $this->property->id,
            'name' => 'Inventory Reversal Workflow',
            'approvable_type' => $approvableType,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('approval_requests')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'approvable_type' => $approvableType,
            'approvable_id' => $approvableId,
            'workflow_id' => 'http-reversal-wf',
            'requester_id' => $this->user->id,
            'status' => $status,
            'notes' => json_encode([
                'request_idempotency_key' => 'request-idem-key',
                'reversal_reason' => $reason,
                'original_transaction_id' => $approvableId,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unauthenticated_attempts_are_redirected(): void
    {
        $this->post(route('operations.inventory.reversals.request'))
            ->assertRedirect(route('login'));

        $this->post(route('operations.inventory.reversals.execute', ['approvalRequest' => (string) Str::ulid()]))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_authenticated_actor_is_forbidden(): void
    {
        $this->actingAs($this->user);

        // User does NOT have 'inventory.reversal.request' or 'inventory.reversal.execute'
        $this->postJson(route('operations.inventory.reversals.request'), [
            'original_inventory_transaction_id' => (string) Str::ulid(),
            'reversal_reason' => 'Reason',
            'request_idempotency_key' => 'key',
        ])->assertStatus(403);

        $this->postJson(route('operations.inventory.reversals.execute', ['approvalRequest' => (string) Str::ulid()]), [
            'execution_idempotency_key' => 'key',
        ])->assertStatus(403);
    }

    public function test_authorized_request_and_replay_boundaries(): void
    {
        $this->seedWorkflow();
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.request');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $payload = [
            'original_inventory_transaction_id' => $tx->id,
            'reversal_reason' => 'Mistake reason',
            'request_idempotency_key' => 'req-idem-http-1',
        ];

        // First attempt creates
        $response = $this->postJson(route('operations.inventory.reversals.request'), $payload);
        $response->assertStatus(200)
            ->assertJsonFragment([
                'outcome' => 'created',
                'status' => 'Pending',
            ]);

        $requestId = $response->json('approval_request_id');
        $this->assertNotNull($requestId);

        // Replay returns identical result without duplicate requests
        $replayResponse = $this->postJson(route('operations.inventory.reversals.request'), $payload);
        $replayResponse->assertStatus(200)
            ->assertJsonFragment([
                'outcome' => 'replayed',
                'approval_request_id' => $requestId,
            ]);

        $this->assertEquals(1, ApprovalRequest::count());
    }

    public function test_malformed_fields_fail_validation_before_service_call(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.request');

        $this->postJson(route('operations.inventory.reversals.request'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'original_inventory_transaction_id',
                'reversal_reason',
                'request_idempotency_key',
            ]);
    }

    public function test_authorized_execution_and_replay_boundaries(): void
    {
        $groupId = $this->seedGroup('enrolled');
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedStock('10.0000');

        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.execute');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        // Seed approved request
        $this->seedApproval($approvalId, 'Approved', $tx->id, $txMorph, 'Correct reason');

        $payload = [
            'execution_idempotency_key' => 'exec-idem-http-1',
        ];

        // Execute approved reversal succeeds
        $response = $this->postJson(route('operations.inventory.reversals.execute', ['approvalRequest' => $approvalId]), $payload);
        $response->assertStatus(200)
            ->assertJsonFragment([
                'outcome' => 'posted',
            ]);

        $reversalTxId = $response->json('reversal_transaction_id');
        $this->assertNotNull($reversalTxId);

        // Replay returns equivalent outcome
        $replayResponse = $this->postJson(route('operations.inventory.reversals.execute', ['approvalRequest' => $approvalId]), $payload);
        $replayResponse->assertStatus(200)
            ->assertJsonFragment([
                'outcome' => 'replayed',
                'reversal_transaction_id' => $reversalTxId,
            ]);

        // Verify transaction counts
        $this->assertEquals(2, InventoryTransaction::count()); // original + 1 reversal
    }

    public function test_execution_on_non_approved_request_fails(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.execute');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        // Seed pending request
        $this->seedApproval($approvalId, 'Pending', $tx->id, $txMorph, 'Reason');

        $payload = [
            'execution_idempotency_key' => 'exec-idem-http-2',
        ];

        $this->postJson(route('operations.inventory.reversals.execute', ['approvalRequest' => $approvalId]), $payload)
            ->assertStatus(500); // Exec service throws validation/rejected exception
    }
}
