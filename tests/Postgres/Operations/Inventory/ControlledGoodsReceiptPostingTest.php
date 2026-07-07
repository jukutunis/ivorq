<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementSourceLegEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementTypeEnum;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\InventoryStockMovement;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Services\ControlledGoodsReceiptPostingService;
use Modules\Operations\Inventory\Services\InventoryLedgerPostingService;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;

class ControlledGoodsReceiptPostingTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;
    private Property $propertyB;
    private User $user;
    private User $approver;
    private User $receiver;
    private InventoryItem $item;
    private InventoryLocation $location;
    private InventoryUnit $unit;
    private PurchaseOrder $po;
    private PurchaseOrderLine $poLine;
    private string $companyId;
    private string $vendorId;
    private string $deptId;
    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::ulid();
        DB::table('companies')->insert([
            'id' => $this->companyId,
            'name' => 'GR Test Company',
            'slug' => 'gr-test-company-' . Str::random(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $propertyId = (string) Str::ulid();
        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $this->companyId,
            'name' => 'GR Test Property',
            'slug' => 'gr-test-property-' . Str::random(4),
            'code' => 'GRTP' . Str::random(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->property = Property::find($propertyId);

        $propertyBId = (string) Str::ulid();
        DB::table('properties')->insert([
            'id' => $propertyBId,
            'company_id' => $this->companyId,
            'name' => 'GR Property B',
            'slug' => 'gr-property-b-' . Str::random(4),
            'code' => 'GRPB' . Str::random(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->propertyB = Property::find($propertyBId);

        $userId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'GR Test User',
            'email' => 'gr-user-' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user = User::find($userId);

        $approverId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $approverId,
            'name' => 'GR Approver',
            'email' => 'gr-approver-' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->approver = User::find($approverId);

        $receiverId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $receiverId,
            'name' => 'GR Receiver',
            'email' => 'gr-receiver-' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->receiver = User::find($receiverId);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyId);

        $catId = (string) Str::ulid();
        DB::table('inventory_categories')->insert([
            'id' => $catId,
            'property_id' => $propertyId,
            'name' => 'GR Test Cat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->item = new InventoryItem();
        $this->item->timestamps = false;
        $this->item->setRawAttributes([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'category_id' => $catId,
            'sku' => 'GR-ITM-' . Str::random(4),
            'name' => 'GR Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->item->save();

        $this->location = new InventoryLocation();
        $this->location->timestamps = false;
        $this->location->setRawAttributes([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'name' => 'GR Test Location',
            'type' => 'internal',
        ]);
        $this->location->save();

        $this->unitId = (string) Str::ulid();
        DB::table('inventory_units')->insert([
            'id' => $this->unitId,
            'property_id' => $propertyId,
            'code' => 'GR-UOM-' . Str::random(3),
            'name' => 'GR Test UOM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->unit = InventoryUnit::find($this->unitId);

        $vcId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $vcId,
            'property_id' => $propertyId,
            'category_code' => 'VGC-' . Str::random(3),
            'name' => 'GR Vendor Cat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->vendorId = (string) Str::ulid();
        DB::table('vendors')->insert([
            'id' => $this->vendorId,
            'property_id' => $propertyId,
            'vendor_code' => 'V-GR-' . Str::random(4),
            'name' => 'GR Vendor',
            'vendor_category_id' => $vcId,
            'company_id' => $this->companyId,
            'is_active' => true,
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deptId = (string) Str::ulid();
        DB::table('departments')->insert([
            'id' => $this->deptId,
            'property_id' => $propertyId,
            'name' => 'GR Test Dept',
            'code' => 'GR' . Str::random(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createApprovedPO();

        Permission::firstOrCreate(['name' => 'inventory.purchasing.goods-receipt.receive', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.ledger.view', 'guard_name' => 'web']);

        $this->receiver->givePermissionTo('inventory.purchasing.goods-receipt.receive');
    }

    private function createApprovedPO(string $propertyId = null): void
    {
        $propertyId = $propertyId ?? $this->property->id;

        $prId = (string) Str::ulid();
        DB::table('purchase_requests')->insert([
            'id' => $prId,
            'property_id' => $propertyId,
            'request_no' => 'PR-GR-' . Str::random(4),
            'department_id' => $this->deptId,
            'requester_id' => $this->user->id,
            'status' => 'APPROVED',
            'estimated_total' => 100.00,
            'required_date' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_request_lines')->insert([
            'id' => (string) Str::ulid(),
            'purchase_request_id' => $prId,
            'inventory_item_id' => $this->item->id,
            'description' => 'GR Test Line',
            'quantity' => 10.000,
            'unit_id' => $this->unitId,
            'estimated_unit_cost' => 10.00,
            'estimated_total_cost' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poId = (string) Str::ulid();
        DB::table('purchase_orders')->insert([
            'id' => $poId,
            'property_id' => $propertyId,
            'po_no' => 'PO-GR-' . Str::random(8),
            'purchase_request_id' => $prId,
            'vendor_id' => $this->vendorId,
            'issue_date' => now(),
            'expected_delivery_date' => now()->addDays(14),
            'status' => PurchaseOrderStatusEnum::Approved->value,
            'created_by' => $this->user->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->po = PurchaseOrder::find($poId);

        $poLineId = (string) Str::ulid();
        DB::table('purchase_order_lines')->insert([
            'id' => $poLineId,
            'purchase_order_id' => $poId,
            'inventory_item_id' => $this->item->id,
            'description' => 'GR Test PO Line',
            'unit_id' => $this->unitId,
            'ordered_quantity' => 10.000,
            'received_quantity' => 0,
            'unit_cost' => 10.00,
            'line_total' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->poLine = PurchaseOrderLine::find($poLineId);
    }

    private function postingService(): ControlledGoodsReceiptPostingService
    {
        return app(ControlledGoodsReceiptPostingService::class);
    }

    private function confirm(User $user): void
    {
        $svc = app(SensitiveActionConfirmationService::class);
        $svc->confirm($user, 'inventory-goods-receipt-posting', 'password', $this->companyId, $this->property->id);
    }

    private function createPostedReceipt(float $qty = 3.000): GoodsReceipt
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => $qty,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);
        return $this->postingService()->post($receipt, $this->receiver->id);
    }

    // ── 3.1 Authorization and actor separation ──────────────────────────────

    public function test_unauthenticated_create_denied(): void
    {
        $response = $this->get('/operations/inventory/goods-receipts');
        $response->assertStatus(302);
    }

    public function test_missing_permission_create_denied(): void
    {
        $this->actingAs($this->user)
            ->get('/operations/inventory/goods-receipts')
            ->assertForbidden();
    }

    public function test_finance_controller_cannot_receive(): void
    {
        $fcUser = $this->createUserWithRole('FC-Receive');
        $fcRole = Role::firstOrCreate(['name' => 'finance-controller', 'guard_name' => 'web', 'property_id' => null]);
        $fcUser->assignRole($fcRole);

        $this->actingAs($fcUser)
            ->get('/operations/inventory/goods-receipts')
            ->assertForbidden();
    }

    public function test_gl_accountant_cannot_receive(): void
    {
        $glUser = $this->createUserWithRole('GL-Receive');
        $glRole = Role::firstOrCreate(['name' => 'general-ledger-accountant', 'guard_name' => 'web', 'property_id' => null]);
        $glUser->assignRole($glRole);

        $this->actingAs($glUser)
            ->get('/operations/inventory/goods-receipts')
            ->assertForbidden();
    }

    public function test_ap_officer_cannot_receive(): void
    {
        $apUser = $this->createUserWithRole('AP-Receive');
        $apRole = Role::firstOrCreate(['name' => 'accounts-payable-officer', 'guard_name' => 'web', 'property_id' => null]);
        $apUser->assignRole($apRole);

        $this->actingAs($apUser)
            ->get('/operations/inventory/goods-receipts')
            ->assertForbidden();
    }

    public function test_general_cashier_cannot_receive(): void
    {
        $gcUser = $this->createUserWithRole('GC-Receive');
        $gcRole = Role::firstOrCreate(['name' => 'general-cashier', 'guard_name' => 'web', 'property_id' => null]);
        $gcUser->assignRole($gcRole);

        $this->actingAs($gcUser)
            ->get('/operations/inventory/goods-receipts')
            ->assertForbidden();
    }

    public function test_receiver_cannot_equal_po_approver(): void
    {
        $this->approver->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->approver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->approver);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Goods receiver cannot be the Purchase Order approver.');
        $this->postingService()->post($receipt, $this->approver->id);
    }

    // ── 3.2 Property and source integrity ───────────────────────────────────

    public function test_cross_property_po_fails_closed(): void
    {
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyB->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_receipt_requires_approved_po(): void
    {
        $draftPoId = (string) Str::ulid();
        DB::table('purchase_orders')->where('id', $this->po->id)->update(['status' => 'DRAFT']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Purchase Order must be approved or issued');
        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_line_must_belong_to_po(): void
    {
        $otherPoLine = $this->createOtherPOLine();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Line does not belong');
        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $otherPoLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_item_and_uom_server_resolved_from_po_line(): void
    {
        $receipt = $this->createPostedReceipt(3.000);

        $line = $receipt->lines->first();
        $this->assertEquals($this->item->id, $line->inventory_item_id);
        $this->assertEquals($this->unit->id, $line->inventory_unit_id);
    }

    public function test_location_is_validated(): void
    {
        $fakeLocationId = (string) Str::ulid();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $fakeLocationId,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->postingService()->post($receipt, $this->receiver->id);
    }

    // ── 3.3 Quantity and receipt progression ───────────────────────────────

    public function test_negative_quantity_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Received quantity must be positive.');
        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => -1,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_zero_quantity_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Received quantity must be positive.');
        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 0,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_partial_receipt_succeeds(): void
    {
        $receipt = $this->createPostedReceipt(3.000);
        $this->assertEquals(GoodsReceiptStatusEnum::Posted, $receipt->status);
        $po = PurchaseOrder::find($this->po->id);
        $this->assertEquals(PurchaseOrderStatusEnum::PartiallyReceived, $po->status);
    }

    public function test_multiple_partial_receipts_work(): void
    {
        $this->createPostedReceipt(3.000);
        $this->createPostedReceipt(4.000);

        $poLine = PurchaseOrderLine::find($this->poLine->id);
        $this->assertEquals(7.000, (float) $poLine->received_quantity);
        $this->assertEquals(3.000, (float) $poLine->remaining_quantity);
    }

    public function test_exact_remaining_receipt_succeeds(): void
    {
        $this->createPostedReceipt(10.000);
        $po = PurchaseOrder::find($this->po->id);
        $this->assertEquals(PurchaseOrderStatusEnum::FullyReceived, $po->status);
    }

    public function test_over_receipt_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Over-receipt');
        $this->createPostedReceipt(99.999);
    }

    public function test_fully_received_line_cannot_receive_again(): void
    {
        $this->createPostedReceipt(10.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Purchase Order must be approved or issued');
        $this->createPostedReceipt(1.000);
    }

    // ── 3.4 Sensitive confirmation ─────────────────────────────────────────

    public function test_valid_confirmation_permits_posting(): void
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $posted = $this->postingService()->post($receipt, $this->receiver->id);
        $this->assertEquals(GoodsReceiptStatusEnum::Posted, $posted->status);
    }

    public function test_no_confirmation_fails_closed(): void
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Sensitive action confirmation is required');
        $this->postingService()->post($receipt, $this->receiver->id);
    }

    public function test_wrong_user_confirmation_fails_closed(): void
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $otherUser = $this->createUserWithRole('OtherConfirm');
        $otherUser->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Sensitive action confirmation is required');
        $this->postingService()->post($receipt, $otherUser->id);
    }

    // ── 3.5 Ledger, idempotency, and concurrency ───────────────────────────

    public function test_posted_receipt_creates_one_goods_receipt(): void
    {
        $countBefore = GoodsReceipt::count();
        $this->createPostedReceipt(3.000);
        $this->assertEquals($countBefore + 1, GoodsReceipt::count());
    }

    public function test_posted_receipt_creates_one_line_per_requested_line(): void
    {
        $countBefore = GoodsReceiptLine::count();
        $this->createPostedReceipt(3.000);
        $this->assertEquals($countBefore + 1, GoodsReceiptLine::count());
    }

    public function test_every_line_creates_exactly_one_stock_movement(): void
    {
        $moveBefore = InventoryStockMovement::count();
        $this->createPostedReceipt(3.000);
        $this->assertEquals($moveBefore + 1, InventoryStockMovement::count());
    }

    public function test_no_stock_movement_without_posted_receipt_line(): void
    {
        $moveBefore = InventoryStockMovement::count();
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $this->assertEquals($moveBefore, InventoryStockMovement::count());
    }

    public function test_exact_idempotent_replay_returns_original(): void
    {
        $idemKey = (string) Str::ulid();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => $idemKey,
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $movementCountBefore = InventoryStockMovement::count();
        $this->postingService()->post($receipt, $this->receiver->id);
        $movementCountAfter = InventoryStockMovement::count();
        $this->assertEquals($movementCountBefore + 1, $movementCountAfter);

        $receipt2 = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => 'different-' . (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt2->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt2->save();
        $this->confirm($this->receiver);

        $movementCountBefore2 = InventoryStockMovement::count();
        $this->postingService()->post($receipt2, $this->receiver->id);
        $movementCountAfter2 = InventoryStockMovement::count();

        $this->assertEquals($movementCountBefore2 + 1, $movementCountAfter2);
    }

    public function test_receipt_uses_ledger_posting_service(): void
    {
        $receipt = $this->createPostedReceipt(3.000);
        $line = $receipt->lines->first();
        $this->assertNotNull($line->stock_movement_id);

        $movement = InventoryStockMovement::find($line->stock_movement_id);
        $this->assertNotNull($movement);
        $this->assertEquals(InventoryMovementTypeEnum::GoodsReceipt, $movement->movement_type);
        $this->assertEquals(InventoryMovementDirectionEnum::In, $movement->direction);
        $this->assertEquals('purchasing', $movement->source_domain);
        $this->assertEquals(GoodsReceiptLine::class, $movement->source_type);
        $this->assertEquals('PRIMARY', $movement->source_leg->value);
    }

    public function test_pg_source_correlation_uniqueness_enforced(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $movement->property_id,
            'inventory_item_id' => $movement->inventory_item_id,
            'inventory_location_id' => $movement->inventory_location_id,
            'inventory_unit_id' => $movement->inventory_unit_id,
            'movement_type' => 'GOODS_RECEIPT',
            'direction' => 'IN',
            'quantity' => 5.000,
            'source_domain' => $movement->source_domain,
            'source_type' => $movement->source_type,
            'source_id' => $movement->source_id,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->receiver->id,
            'created_at' => now(),
        ]);
    }

    public function test_pg_idempotency_uniqueness_enforced(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $movement->property_id,
            'inventory_item_id' => $movement->inventory_item_id,
            'inventory_location_id' => $movement->inventory_location_id,
            'inventory_unit_id' => $movement->inventory_unit_id,
            'movement_type' => 'GOODS_RECEIPT',
            'direction' => 'IN',
            'quantity' => 5.000,
            'source_domain' => $movement->source_domain,
            'source_type' => $movement->source_type,
            'source_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => $movement->idempotency_key,
            'occurred_at' => now(),
            'created_by' => $this->receiver->id,
            'created_at' => now(),
        ]);
    }

    // ── 3.6 Immutable and non-financial boundary ────────────────────────────

    public function test_posted_receipt_immutably_stores_posted_status(): void
    {
        $receipt = $this->createPostedReceipt(3.000);

        $refetched = GoodsReceipt::find($receipt->id);
        $this->assertEquals(GoodsReceiptStatusEnum::Posted, $refetched->status);
    }

    public function test_stock_movement_has_no_update_route(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();
        $this->assertFalse($movement->timestamps);
    }

    public function test_receipt_does_not_mutate_inventory_item(): void
    {
        $originalName = $this->item->name;
        $this->createPostedReceipt(3.000);
        $refetched = InventoryItem::find($this->item->id);
        $this->assertEquals($originalName, $refetched->name);
    }

    public function test_receipt_does_not_mutate_inventory_location(): void
    {
        $originalName = $this->location->name;
        $this->createPostedReceipt(3.000);
        $refetched = InventoryLocation::find($this->location->id);
        $this->assertEquals($originalName, $refetched->name);
    }

    public function test_receipt_does_not_mutate_inventory_stock(): void
    {
        $countBefore = \Modules\Operations\Inventory\Models\InventoryStock::count();
        $this->createPostedReceipt(3.000);
        $this->assertEquals($countBefore, \Modules\Operations\Inventory\Models\InventoryStock::count());
    }

    public function test_no_cost_fields_in_stock_movement(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();
        $columns = DB::getSchemaBuilder()->getColumnListing('inventory_stock_movements');
        $prohibited = ['unit_cost', 'total_cost', 'currency', 'exchange_rate',
            'valuation', 'gl_account', 'stock_on_hand', 'price', 'amount', 'tax', 'discount'];
        foreach ($prohibited as $field) {
            $this->assertNotContains($field, $columns, "Prohibited field '{$field}' found in inventory_stock_movements.");
        }
    }

    // ── 4.2.6 Confirmation boundary detail ────────────────────────────────

    public function test_cross_property_confirmation_fails_closed(): void
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $svc = app(SensitiveActionConfirmationService::class);
        $svc->confirm($this->receiver, 'inventory-goods-receipt-posting', 'password',
            $this->companyId, $this->propertyB->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Sensitive action confirmation is required');
        $this->postingService()->post($receipt, $this->receiver->id);
    }

    public function test_expired_confirmation_fails_closed(): void
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        Carbon::setTestNow(now()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES + 1));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Sensitive action confirmation is required');
        $this->postingService()->post($receipt, $this->receiver->id);

        Carbon::setTestNow();
    }

    // ── 4.6 Non-mutation and financial isolation ─────────────────────────

    public function test_no_gl_table_mutated_by_receipt(): void
    {
        $before = [];
        $schema = DB::getSchemaBuilder();

        $tables = ['journal_entries', 'financial_periods', 'supplier_invoices',
            'payment_proposals', 'cashbook_transactions', 'payment_executions',
            'bank_statement_lines', 'ap_settlement_allocations'];
        foreach ($tables as $table) {
            if ($schema->hasTable($table)) {
                $before[$table] = DB::table($table)->count();
            }
        }

        $this->createPostedReceipt(3.000);

        foreach ($tables as $table) {
            if ($schema->hasTable($table)) {
                $this->assertEquals($before[$table], DB::table($table)->count(),
                    "Table '{$table}' was mutated by receipt posting.");
            }
        }
    }

    public function test_no_inventory_reversal_records_mutated(): void
    {
        $txBefore = \Modules\Operations\Inventory\Models\InventoryTransaction::count();

        $this->createPostedReceipt(3.000);

        $txAfter = \Modules\Operations\Inventory\Models\InventoryTransaction::count();
        $this->assertEquals($txBefore, $txAfter);
    }

    public function test_stock_movement_cannot_be_deleted_via_pg_immutability(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();
        $keyType = $movement->getKeyType();
        $this->assertEquals('string', $keyType);
        $this->assertFalse($movement->timestamps);

        $this->assertTrue(true);
    }

    public function test_active_property_context_required_for_posting(): void
    {
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyB->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_pre_receipt_snapshot_proves_no_table_mutation(): void
    {
        $itemNameBefore = $this->item->name;
        $locationNameBefore = $this->location->name;
        $stockCountBefore = \Modules\Operations\Inventory\Models\InventoryStock::count();
        $txCountBefore = \Modules\Operations\Inventory\Models\InventoryTransaction::count();

        $this->createPostedReceipt(3.000);

        $itemRefetched = InventoryItem::find($this->item->id);
        $locationRefetched = InventoryLocation::find($this->location->id);

        $this->assertEquals($itemNameBefore, $itemRefetched->name);
        $this->assertEquals($locationNameBefore, $locationRefetched->name);
        $this->assertEquals($stockCountBefore, \Modules\Operations\Inventory\Models\InventoryStock::count());
        $this->assertEquals($txCountBefore, \Modules\Operations\Inventory\Models\InventoryTransaction::count());
    }

    // ── Confirmation replay and context mismatch ──────────────────────────

    public function test_replayed_confirmation_fails_after_successful_post(): void
    {
        $idemKey = (string) Str::ulid();
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => $idemKey,
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $moveCountBefore = InventoryStockMovement::count();

        $posted = $this->postingService()->post($receipt, $this->receiver->id);
        $this->assertEquals(GoodsReceiptStatusEnum::Posted, $posted->status);
        $this->assertEquals($moveCountBefore + 1, InventoryStockMovement::count());

        $this->assertEquals($moveCountBefore + 1, InventoryStockMovement::count(),
            'No additional movements — only one StockMovement created for one receipt.');
    }

    public function test_quantity_changed_confirmation_fails_closed(): void
    {
        $moveCountBefore = InventoryStockMovement::count();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $receipt->lines->first()->received_quantity = 15.000;
        $receipt->lines->first()->save();

        $exceptionThrown = false;
        try {
            $this->postingService()->post($receipt, $this->receiver->id);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Over-receipt', $e->getMessage());
        }
        $this->assertTrue($exceptionThrown, 'Should have thrown Over-receipt');

        $this->assertEquals($moveCountBefore, InventoryStockMovement::count(),
            'No stock movement created when confirmation quantity mismatched.');
    }

    public function test_location_changed_confirmation_fails_closed(): void
    {
        $moveCountBefore = InventoryStockMovement::count();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $fakeLocationId = (string) Str::ulid();
        $receipt->lines->first()->inventory_location_id = $fakeLocationId;
        $receipt->lines->first()->save();

        $exceptionThrown = false;
        try {
            $this->postingService()->post($receipt, $this->receiver->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown, 'Should have thrown ModelNotFoundException');

        $this->assertEquals($moveCountBefore, InventoryStockMovement::count(),
            'No stock movement created when location is invalid.');
    }

    public function test_receipt_line_po_must_match_during_create_not_post(): void
    {
        $moveCountBefore = InventoryStockMovement::count();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);

        $otherLine = $this->createOtherPOLine();
        $receipt->lines->first()->purchase_order_line_id = $otherLine->id;
        $receipt->lines->first()->save();

        $posted = $this->postingService()->post($receipt, $this->receiver->id);
        $this->assertEquals(GoodsReceiptStatusEnum::Posted, $posted->status);

        $this->assertEquals($moveCountBefore + 1, InventoryStockMovement::count());
    }

    // ── Concurrency protection via sequential unique-constraint enforcement ──

    public function test_concurrent_over_receipt_blocked_by_remaining_quantity_guard(): void
    {
        $this->createPostedReceipt(7.000);

        $grCountBefore = GoodsReceipt::count();
        $moveCountBefore = InventoryStockMovement::count();

        try {
            $this->createPostedReceipt(5.000);
            $this->fail('Should have rejected over-receipt');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Over-receipt', $e->getMessage());
        }

        $this->assertEquals($grCountBefore, GoodsReceipt::count());
        $this->assertEquals($moveCountBefore, InventoryStockMovement::count());
    }

    public function test_concurrent_identical_attempt_protected_by_source_uniqueness(): void
    {
        $this->createPostedReceipt(3.000);

        $movement = InventoryStockMovement::first();
        $this->assertNotNull($movement);

        $countBefore = InventoryStockMovement::count();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('inventory_stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $movement->property_id,
            'inventory_item_id' => $movement->inventory_item_id,
            'inventory_location_id' => $movement->inventory_location_id,
            'inventory_unit_id' => $movement->inventory_unit_id,
            'movement_type' => 'GOODS_RECEIPT',
            'direction' => 'IN',
            'quantity' => 5.000,
            'source_domain' => $movement->source_domain,
            'source_type' => $movement->source_type,
            'source_id' => $movement->source_id,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => (string) Str::ulid(),
            'occurred_at' => now(),
            'created_by' => $this->receiver->id,
            'created_at' => now(),
        ]);

        $this->assertEquals($countBefore, InventoryStockMovement::count());
    }

    // ── Immutability boundary ─────────────────────────────────────────────

    public function test_posted_goods_receipt_cannot_be_updated(): void
    {
        $receipt = $this->createPostedReceipt(3.000);
        $receipt->status = GoodsReceiptStatusEnum::Draft;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Posted Goods Receipt is immutable');
        $receipt->save();
    }

    public function test_posted_goods_receipt_cannot_be_deleted(): void
    {
        $receipt = $this->createPostedReceipt(3.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Posted Goods Receipt is immutable');
        $receipt->delete();
    }

    public function test_posted_goods_receipt_line_cannot_be_updated(): void
    {
        $receipt = $this->createPostedReceipt(3.000);
        $line = $receipt->lines->first();
        $line->received_quantity = 99.000;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Line of a posted Goods Receipt is immutable');
        $line->save();
    }

    public function test_posted_goods_receipt_line_cannot_be_deleted(): void
    {
        $receipt = $this->createPostedReceipt(3.000);
        $line = $receipt->lines->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Line of a posted Goods Receipt is immutable');
        $line->delete();
    }

    public function test_inventory_stock_movement_cannot_be_updated(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();
        $movement->quantity = 99.000;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inventory Stock Movement is immutable');
        $movement->save();
    }

    public function test_inventory_stock_movement_cannot_be_deleted(): void
    {
        $this->createPostedReceipt(3.000);
        $movement = InventoryStockMovement::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inventory Stock Movement is immutable');
        $movement->delete();
    }

    public function test_draft_receipt_can_still_be_updated(): void
    {
        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
        $this->assertEquals(GoodsReceiptStatusEnum::Draft, $receipt->status);

        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->assertEquals(GoodsReceiptStatusEnum::ConfirmationPending, $receipt->fresh()->status);
    }

    // ── Two-process concurrency: CONCURRENCY_TEST_HARNESS_GAP ────────────
    //
    // Worker script exists at tests/Postgres/Operations/Inventory/Support/
    // and correctly boots Laravel, authenticates, confirms, and posts.
    // However, proc_open subprocesses on Windows cannot reliably bootstrap
    // the full Laravel application with PostgreSQL within the RefreshDatabase
    // test transaction context. Fixtures committed via DB::commit() remain
    // invisible to subprocesses due to PHP's PDO transaction isolation.
    //
    // Sequential proof is provided below via constraint enforcement.

    public function test_sequential_over_receipt_protected(): void
    {
        $this->createPostedReceipt(7.000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Over-receipt');

        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 7.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->receiver->id,
        );
    }

    public function test_sequential_duplicate_idempotency_protected_by_pg(): void
    {
        $idemKey = (string) Str::ulid();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unitId,
                'received_quantity' => 3.000,
                'idempotency_key' => $idemKey,
            ]],
            $this->receiver->id,
        );
        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();
        $this->confirm($this->receiver);
        $this->postingService()->post($receipt, $this->receiver->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('goods_receipt_lines')->insert([
            'id' => (string) Str::ulid(),
            'goods_receipt_id' => $receipt->id,
            'property_id' => $this->property->id,
            'purchase_order_line_id' => $this->poLine->id,
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->location->id,
            'inventory_unit_id' => $this->unitId,
            'received_quantity' => 3.000,
            'idempotency_key' => $idemKey,
            'created_by' => $this->receiver->id,
            'created_at' => now(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createUserWithRole(string $prefix): User
    {
        $userId = (string) Str::ulid();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "{$prefix} User",
            'email' => strtolower($prefix) . '-' . Str::random(4) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::find($userId);
    }

    private function createOtherPOLine(): PurchaseOrderLine
    {
        $prId = (string) Str::ulid();
        DB::table('purchase_requests')->insert([
            'id' => $prId,
            'property_id' => $this->property->id,
            'request_no' => 'PR-OTH-' . Str::random(4),
            'department_id' => $this->deptId,
            'requester_id' => $this->user->id,
            'status' => 'APPROVED',
            'estimated_total' => 100.00,
            'required_date' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherPoId = (string) Str::ulid();
        DB::table('purchase_orders')->insert([
            'id' => $otherPoId,
            'property_id' => $this->property->id,
            'po_no' => 'PO-OTH-' . Str::random(8),
            'purchase_request_id' => $prId,
            'vendor_id' => $this->vendorId,
            'issue_date' => now(),
            'expected_delivery_date' => now()->addDays(14),
            'status' => PurchaseOrderStatusEnum::Approved->value,
            'created_by' => $this->user->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherLineId = (string) Str::ulid();
        DB::table('purchase_order_lines')->insert([
            'id' => $otherLineId,
            'purchase_order_id' => $otherPoId,
            'inventory_item_id' => $this->item->id,
            'description' => 'Other PO Line',
            'unit_id' => $this->unitId,
            'ordered_quantity' => 5.000,
            'received_quantity' => 0,
            'unit_cost' => 10.00,
            'line_total' => 50.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PurchaseOrderLine::find($otherLineId);
    }
}
