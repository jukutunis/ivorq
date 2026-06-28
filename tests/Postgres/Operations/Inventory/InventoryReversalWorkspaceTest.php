<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
use Modules\Foundation\Authorization\Models\Permission;

class InventoryReversalWorkspaceTest extends PostgresTestCase
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
            'sku'                   => 'ITM-UIW-999',
            'name'                  => 'UI Reversal Item',
            'inventory_type'        => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active'             => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name'        => 'UI Reversal Warehouse',
            'type'        => 'internal',
        ]);

        Permission::firstOrCreate(['name' => 'inventory.reversal.request', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.reversal.execute', 'guard_name' => 'web']);
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
            'id' => 'ui-reversal-wf',
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
            'workflow_id' => 'ui-reversal-wf',
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

        if ($status === 'Approved') {
            DB::table('approval_steps')->insertOrIgnore([
                'id' => 'ui-reversal-step',
                'workflow_id' => 'ui-reversal-wf',
                'sequence' => 1,
                'name' => 'Manager Approval',
                'required_approvals' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('approval_actions')->insert([
                'id' => (string) Str::ulid(),
                'approval_request_id' => $id,
                'approval_step_id' => 'ui-reversal-step',
                'user_id' => $this->user->id,
                'action_type' => 'Approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_unauthenticated_actor_cannot_open_workspace(): void
    {
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_actor_cannot_open_workspace(): void
    {
        $this->actingAs($this->user);
        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]))
            ->assertStatus(403);
    }

    public function test_authorized_actor_receives_inertia_props_correctly(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.request');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $response = $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Inventory/InventoryReversalWorkspace')
            ->has('transaction')
            ->where('transaction.id', $tx->id)
            ->where('isEligible', true)
            ->where('blocker', null)
            ->has('idempotencyKey')
            ->where('existingApproval', null)
            ->where('existingReversal', null)
            ->where('isExecutionAvailable', false)
            ->where('executionIdempotencyKey', null)
        );
    }

    public function test_blocked_original_transaction_type_renders_blocker(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.request');

        $tx = $this->createTransaction(TransactionTypeEnum::AdjustmentIn, 1, '5.0000', '10.0000', '50.0000');

        $response = $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Inventory/InventoryReversalWorkspace')
            ->where('isEligible', false)
            ->where('blocker', 'Candidate transaction type is not eligible for Reversal v1.')
            ->where('idempotencyKey', null)
        );
    }

    public function test_final_approved_linked_approval_request_renders_props(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.execute');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Approved', $tx->id, $txMorph, 'Correct reason');

        $response = $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Inventory/InventoryReversalWorkspace')
            ->where('isEligible', false)
            ->where('existingApproval.status', 'Approved')
            ->where('isExecutionAvailable', true)
            ->has('executionIdempotencyKey')
            ->where('requesterName', $this->user->name)
            ->where('approverName', $this->user->name)
        );
    }

    public function test_non_final_approval_does_not_expose_execution(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.execute');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Pending', $tx->id, $txMorph, 'Correct reason');

        $response = $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Inventory/InventoryReversalWorkspace')
            ->where('isExecutionAvailable', false)
            ->where('executionIdempotencyKey', null)
        );
    }

    public function test_existing_linked_reversal_does_not_expose_execution(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.execute');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Approved', $tx->id, $txMorph, 'Correct reason');

        // Create mock reversal referencing the original
        $reversalTx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 2, '-5.0000', '10.0000', '-50.0000');
        $reversalTx->reverses_inventory_transaction_id = $tx->id;
        $reversalTx->save();

        $response = $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Inventory/InventoryReversalWorkspace')
            ->where('isExecutionAvailable', false)
            ->where('executionIdempotencyKey', null)
            ->where('blocker', 'This transaction has already been reversed.')
        );
    }

    public function test_executed_reversal_renders_state_3_evidence_correctly(): void
    {
        $this->actingAs($this->user);
        $this->user->givePermissionTo('inventory.reversal.execute');

        $tx = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt, 1, '5.0000', '10.0000', '50.0000');

        $approvalId = (string) Str::ulid();
        $txMorph = (new InventoryTransaction())->getMorphClass();
        $this->seedApproval($approvalId, 'Approved', $tx->id, $txMorph, 'Correct reason');

        $reversalTx = $this->createTransaction(TransactionTypeEnum::Reversal, 2, '-5.0000', '10.0000', '-50.0000');
        $reversalTx->reverses_inventory_transaction_id = $tx->id;
        $reversalTx->posted_by = $this->user->id;
        $reversalTx->save();

        $response = $this->get(route('operations.inventory.reversals.show', ['transaction' => $tx->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Operations/Inventory/InventoryReversalWorkspace')
            ->where('isEligible', false)
            ->where('blocker', 'This transaction has already been reversed.')
            ->where('idempotencyKey', null)
            ->where('isExecutionAvailable', false)
            ->where('executionIdempotencyKey', null)
            ->where('existingReversal.id', $reversalTx->id)
            ->where('auditEvidenceStatus', 'recorded')
            ->where('executorName', $this->user->name)
            ->where('requesterName', $this->user->name)
            ->where('approverName', $this->user->name)
        );
    }
}
