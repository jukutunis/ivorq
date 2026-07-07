<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\InventoryMovementDirectionEnum;
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
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;

class ControlledGoodsReceiptPostingTest extends PostgresTestCase
{
    use RefreshDatabase;

    // protected $seed = true;

    private Property $property;
    private User $user;
    private User $approver;
    private InventoryItem $item;
    private InventoryLocation $location;
    private InventoryUnit $unit;
    private PurchaseOrder $po;
    private PurchaseOrderLine $poLine;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = (string) \Illuminate\Support\Str::ulid();
        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'GR Test Company',
            'slug' => 'gr-test-company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $propertyId = (string) \Illuminate\Support\Str::ulid();
        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'GR Test Property',
            'slug' => 'gr-test-property',
            'code' => 'GRTP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->property = Property::find($propertyId);

        $userId = (string) \Illuminate\Support\Str::ulid();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'GR Test User',
            'email' => 'gr-user-' . \Illuminate\Support\Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user = User::find($userId);

        $approverId = (string) \Illuminate\Support\Str::ulid();
        DB::table('users')->insert([
            'id' => $approverId,
            'name' => 'GR Approver',
            'email' => 'gr-approver-' . \Illuminate\Support\Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->approver = User::find($approverId);

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyId);

        $catId = (string) \Illuminate\Support\Str::ulid();
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
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'property_id' => $propertyId,
            'category_id' => $catId,
            'sku' => 'GR-ITM-' . \Illuminate\Support\Str::random(4),
            'name' => 'GR Test Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->item->save();

        $this->location = new InventoryLocation();
        $this->location->timestamps = false;
        $this->location->setRawAttributes([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'property_id' => $propertyId,
            'name' => 'GR Test Location',
            'type' => 'internal',
        ]);
        $this->location->save();

        $unitId = (string) \Illuminate\Support\Str::ulid();
        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $propertyId,
            'code' => 'GR-UOM',
            'name' => 'GR Test UOM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->unit = InventoryUnit::find($unitId);

        $vcId = (string) \Illuminate\Support\Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $vcId,
            'property_id' => $propertyId,
            'category_code' => 'VGC',
            'name' => 'GR Vendor Cat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vendorId = (string) \Illuminate\Support\Str::ulid();
        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $propertyId,
            'vendor_code' => 'V-GR-' . \Illuminate\Support\Str::random(4),
            'name' => 'GR Vendor',
            'vendor_category_id' => $vcId,
            'company_id' => $companyId,
            'is_active' => true,
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deptId = (string) \Illuminate\Support\Str::ulid();
        DB::table('departments')->insert([
            'id' => $deptId,
            'property_id' => $propertyId,
            'name' => 'GR Test Dept',
            'code' => 'GR' . \Illuminate\Support\Str::random(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prId = (string) \Illuminate\Support\Str::ulid();
        DB::table('purchase_requests')->insert([
            'id' => $prId,
            'property_id' => $propertyId,
            'request_no' => 'PR-GR-' . \Illuminate\Support\Str::random(4),
            'department_id' => $deptId,
            'requester_id' => $userId,
            'status' => 'APPROVED',
            'estimated_total' => 100.00,
            'required_date' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_request_lines')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'purchase_request_id' => $prId,
            'inventory_item_id' => $this->item->id,
            'description' => 'GR Test Line',
            'quantity' => 10.000,
            'unit_id' => $unitId,
            'estimated_unit_cost' => 10.00,
            'estimated_total_cost' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poId = (string) \Illuminate\Support\Str::ulid();
        DB::table('purchase_orders')->insert([
            'id' => $poId,
            'property_id' => $propertyId,
            'po_no' => 'PO-GR-' . \Illuminate\Support\Str::random(8),
            'purchase_request_id' => $prId,
            'vendor_id' => $vendorId,
            'issue_date' => now(),
            'status' => PurchaseOrderStatusEnum::Approved->value,
            'created_by' => $userId,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->po = PurchaseOrder::find($poId);

        $poLineId = (string) \Illuminate\Support\Str::ulid();
        DB::table('purchase_order_lines')->insert([
            'id' => $poLineId,
            'purchase_order_id' => $poId,
            'inventory_item_id' => $this->item->id,
            'unit_id' => $unitId,
            'ordered_quantity' => 10.000,
            'received_quantity' => 0,
            'unit_cost' => 10.00,
            'line_total' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->poLine = PurchaseOrderLine::find($poLineId);

        Permission::firstOrCreate(['name' => 'inventory.purchasing.goods-receipt.receive', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.ledger.view', 'guard_name' => 'web']);
    }

    private function postingService(): ControlledGoodsReceiptPostingService
    {
        return app(ControlledGoodsReceiptPostingService::class);
    }

    public function test_can_create_receipt_draft(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [
                [
                    'purchase_order_line_id' => $this->poLine->id,
                    'inventory_location_id' => $this->location->id,
                    'inventory_unit_id' => $this->unit->id,
                    'received_quantity' => 5.000,
                    'idempotency_key' => (string) Str::ulid(),
                ],
            ],
            $this->user->id,
        );

        $this->assertNotNull($receipt);
        $this->assertEquals(GoodsReceiptStatusEnum::Draft, $receipt->status);
        $this->assertCount(1, $receipt->lines);
    }

    public function test_over_receipt_prevented(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Over-receipt');

        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 99.999,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->user->id,
        );
    }

    public function test_zero_quantity_rejected(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Received quantity must be positive.');

        $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 0,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->user->id,
        );
    }

    public function test_receipt_creates_ledger_movement(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->user->id,
        );

        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $svc = app(SensitiveActionConfirmationService::class);
        $svc->confirm($this->user, 'inventory-goods-receipt-posting', 'password', $this->property->company_id, $this->property->id);

        $moveCount = InventoryStockMovement::count();
        $this->postingService()->post($receipt, $this->user->id);
        $this->assertEquals($moveCount + 1, InventoryStockMovement::count());
    }

    public function test_partial_receipt_sets_partially_received(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->user->id,
        );

        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $svc = app(SensitiveActionConfirmationService::class);
        $svc->confirm($this->user, 'inventory-goods-receipt-posting', 'password', $this->property->company_id, $this->property->id);

        $this->postingService()->post($receipt, $this->user->id);
        $po = PurchaseOrder::find($this->po->id);
        $this->assertEquals(PurchaseOrderStatusEnum::PartiallyReceived->value, $po->status);
    }

    public function test_full_receipt_sets_fully_received(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 10.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->user->id,
        );

        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $svc = app(SensitiveActionConfirmationService::class);
        $svc->confirm($this->user, 'inventory-goods-receipt-posting', 'password', $this->property->company_id, $this->property->id);

        $this->postingService()->post($receipt, $this->user->id);
        $po = PurchaseOrder::find($this->po->id);
        $this->assertEquals(PurchaseOrderStatusEnum::FullyReceived->value, $po->status);
    }

    public function test_no_mutable_stock_balance_written(): void
    {
        $this->user->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $stockCount = \Modules\Operations\Inventory\Models\InventoryStock::count();

        $receipt = $this->postingService()->createDraft(
            $this->po->id,
            [[
                'purchase_order_line_id' => $this->poLine->id,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 3.000,
                'idempotency_key' => (string) Str::ulid(),
            ]],
            $this->user->id,
        );

        $receipt->status = GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $svc = app(SensitiveActionConfirmationService::class);
        $svc->confirm($this->user, 'inventory-goods-receipt-posting', 'password', $this->property->company_id, $this->property->id);

        $this->postingService()->post($receipt, $this->user->id);
        $this->assertEquals($stockCount, \Modules\Operations\Inventory\Models\InventoryStock::count());
    }
}
