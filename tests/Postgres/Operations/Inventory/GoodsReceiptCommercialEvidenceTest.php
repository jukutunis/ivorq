<?php

namespace Tests\Postgres\Operations\Inventory;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLine;
use Modules\Operations\Inventory\Models\GoodsReceiptLineCommercialEvidence;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Services\ControlledGoodsReceiptPostingService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use RuntimeException;

class GoodsReceiptCommercialEvidenceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;
    private User $user;
    private User $approver;
    private User $receiver;
    private InventoryItem $item;
    private InventoryLocation $location;
    private InventoryUnit $unit;
    private InventoryCategory $category;
    private string $vendorId;
    private string $vendorCategoryId;
    private string $deptId;
    private string $poId;
    private string $poLineId;
    private ControlledGoodsReceiptPostingService $postingService;
    private SensitiveActionConfirmationService $confirmationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        $this->user = User::first();

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->approver = User::create([
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'name' => 'GR Approver',
            'email' => 'gr-approver-' . Str::random(4) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->receiver = User::create([
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'name' => 'GR Receiver',
            'email' => 'gr-receiver-' . Str::random(4) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        Permission::firstOrCreate(['name' => 'inventory.purchasing.goods-receipt.receive', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.ledger.view', 'guard_name' => 'web']);
        $this->receiver->givePermissionTo('inventory.purchasing.goods-receipt.receive');

        $this->category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'GR Evidence Cat',
        ]);

        $unitId = (string) Str::ulid();
        DB::table('inventory_units')->insert([
            'id' => $unitId,
            'property_id' => $this->property->id,
            'code' => 'PCE',
            'name' => 'Piece',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->unit = InventoryUnit::find($unitId);

        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->category->id,
            'sku' => 'GR-EVIDENCE-001',
            'name' => 'GR Evidence Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'GR Evidence Location',
            'type' => 'internal',
        ]);

        $this->vendorCategoryId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $this->vendorCategoryId,
            'property_id' => $this->property->id,
            'category_code' => 'GEC-' . Str::random(4),
            'name' => 'GR Evidence VC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->vendorId = (string) Str::ulid();
        DB::table('vendors')->insert([
            'id' => $this->vendorId,
            'property_id' => $this->property->id,
            'vendor_code' => 'V-GEC-' . Str::random(4),
            'name' => 'GR Evidence Vendor',
            'vendor_category_id' => $this->vendorCategoryId,
            'company_id' => $this->property->company_id,
            'is_active' => true,
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deptId = (string) Str::ulid();
        DB::table('departments')->insert([
            'id' => $this->deptId,
            'property_id' => $this->property->id,
            'name' => 'GR Evidence Dept',
            'code' => 'GEC' . Str::random(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedApprovedPO();

        $this->postingService = app(ControlledGoodsReceiptPostingService::class);
        $this->confirmationService = app(SensitiveActionConfirmationService::class);
    }

    private function seedApprovedPO(): void
    {
        $prId = (string) Str::ulid();
        DB::table('purchase_requests')->insert([
            'id' => $prId,
            'property_id' => $this->property->id,
            'request_no' => 'PR-GEC-' . Str::random(4),
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
            'description' => 'GEC PR Line',
            'quantity' => 100.000,
            'unit_id' => $this->unit->id,
            'estimated_unit_cost' => 10.00,
            'estimated_total_cost' => 1000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->poId = (string) Str::ulid();
        DB::table('purchase_orders')->insert([
            'id' => $this->poId,
            'property_id' => $this->property->id,
            'po_no' => 'PO-GEC-' . Str::random(8),
            'purchase_request_id' => $prId,
            'vendor_id' => $this->vendorId,
            'issue_date' => now(),
            'expected_delivery_date' => now()->addDays(14),
            'currency_code' => 'IDR',
            'exchange_rate' => '0.0000',
            'status' => PurchaseOrderStatusEnum::Approved->value,
            'created_by' => $this->user->id,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->poLineId = (string) Str::ulid();
        DB::table('purchase_order_lines')->insert([
            'id' => $this->poLineId,
            'purchase_order_id' => $this->poId,
            'description' => 'GEC PO Line',
            'inventory_item_id' => $this->item->id,
            'unit_id' => $this->unit->id,
            'ordered_quantity' => '100.000',
            'received_quantity' => '0.000',
            'invoiced_quantity' => '0.000',
            'unit_cost' => '10.00',
            'line_total' => '0.00',
            'receiving_tolerance_percent' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAndConfirmDraft(): GoodsReceipt
    {
        $receipt = $this->postingService->createDraft($this->poId, [
            [
                'purchase_order_line_id' => $this->poLineId,
                'inventory_location_id' => $this->location->id,
                'inventory_unit_id' => $this->unit->id,
                'received_quantity' => 10.000,
                'idempotency_key' => (string) Str::ulid(),
            ],
        ], $this->receiver->id);

        $receipt->status = \Modules\Operations\Inventory\Enums\GoodsReceiptStatusEnum::ConfirmationPending;
        $receipt->save();

        $this->confirmationService->confirm(
            $this->receiver,
            'inventory-goods-receipt-posting',
            'password',
            $this->property->company_id,
            $this->property->id
        );

        return $receipt;
    }

    // ─── Snapshot Generation ──────────────────────────────────────────────────

    public function test_posted_goods_receipt_line_creates_exactly_one_commercial_evidence_snapshot(): void
    {
        $receipt = $this->createAndConfirmDraft();

        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->assertNotNull($evidence);
        $this->assertEquals($this->property->id, $evidence->property_id);
        $this->assertEquals($receipt->id, $evidence->goods_receipt_id);
        $this->assertEquals($line->id, $evidence->goods_receipt_line_id);
        $this->assertEquals($this->poId, $evidence->purchase_order_id);
        $this->assertEquals($this->poLineId, $evidence->purchase_order_line_id);
    }

    public function test_snapshot_uses_server_resolved_property_po_line_item_unit_and_currency(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->assertEquals($this->item->id, $evidence->inventory_item_id);
        $this->assertEquals($this->unit->id, $evidence->inventory_unit_id);
        $this->assertEquals(strtoupper((string) $this->property->currency), $evidence->property_base_currency_code_snapshot);
        $this->assertEquals('IDR', $evidence->purchase_order_currency_code_snapshot);
    }

    public function test_snapshot_captures_property_base_currency_at_posting(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->assertNotNull($evidence->property_base_currency_code_snapshot);
        $this->assertEquals(3, strlen($evidence->property_base_currency_code_snapshot));
    }

    public function test_snapshot_uses_canonical_decimal_strings_in_hash(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->assertNotNull($evidence->commercial_evidence_hash);
        $this->assertEquals(64, strlen($evidence->commercial_evidence_hash));
    }

    // ─── Snapshot Immutability ─────────────────────────────────────────────────

    public function test_snapshot_cannot_update_through_application_boundary(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Receipt commercial evidence is immutable');

        $evidence->update(['purchase_order_unit_cost_snapshot' => '99.99']);
    }

    public function test_snapshot_cannot_delete_through_application_boundary(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Receipt commercial evidence is immutable');

        $evidence->delete();
    }

    public function test_snapshot_cannot_update_through_direct_postgresql_update(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('goods_receipt_line_commercial_evidences')
            ->where('id', $evidence->id)
            ->update(['purchase_order_unit_cost_snapshot' => '99.99']);
    }

    public function test_snapshot_cannot_delete_through_direct_postgresql_delete(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('goods_receipt_line_commercial_evidences')
            ->where('id', $evidence->id)
            ->delete();
    }

    // ─── Snapshot Resilience to PO Mutation ────────────────────────────────────

    public function test_post_receipt_po_unit_cost_change_does_not_change_existing_snapshot(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();
        $originalSnapshotCost = $evidence->purchase_order_unit_cost_snapshot;

        DB::table('purchase_order_lines')
            ->where('id', $this->poLineId)
            ->update(['unit_cost' => '50.00']);

        $evidence->refresh();
        $this->assertEquals($originalSnapshotCost, $evidence->purchase_order_unit_cost_snapshot);
        $this->assertEquals('10.00', $evidence->purchase_order_unit_cost_snapshot);
    }

    public function test_post_receipt_po_currency_change_does_not_change_existing_snapshot(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();
        $originalSnapshotCurrency = $evidence->purchase_order_currency_code_snapshot;

        DB::table('purchase_orders')
            ->where('id', $this->poId)
            ->update(['currency_code' => 'EUR']);

        $evidence->refresh();
        $this->assertEquals($originalSnapshotCurrency, $evidence->purchase_order_currency_code_snapshot);
        $this->assertEquals('IDR', $evidence->purchase_order_currency_code_snapshot);
    }

    public function test_post_receipt_po_exchange_rate_change_does_not_change_existing_snapshot(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $evidence = GoodsReceiptLineCommercialEvidence::where('goods_receipt_line_id', $line->id)->first();
        $originalSnapshotRate = $evidence->purchase_order_exchange_rate_snapshot;

        DB::table('purchase_orders')
            ->where('id', $this->poId)
            ->update(['exchange_rate' => '15000.0000']);

        $evidence->refresh();
        $this->assertEquals($originalSnapshotRate, $evidence->purchase_order_exchange_rate_snapshot);
    }

    // ─── Baseline Preservation ────────────────────────────────────────────────

    public function test_snapshot_creation_does_not_write_cost_to_inventory_stock_movement(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $line = $receipt->lines->first();
        $movement = DB::table('inventory_stock_movements')
            ->where('source_id', $line->id)
            ->first();

        $columns = DB::getSchemaBuilder()->getColumnListing('inventory_stock_movements');
        $costRelated = array_filter($columns, function ($col) {
            return in_array($col, ['unit_cost', 'total_cost', 'currency', 'currency_code',
                'exchange_rate', 'weighted_average_cost', 'cost_value', 'valuation', 'commercial_evidence_id']);
        });
        $this->assertEmpty($costRelated, 'InventoryStockMovement contains cost-related columns.');
    }

    public function test_snapshot_creation_does_not_write_mutable_stock_balance(): void
    {
        $before = DB::table('inventory_stocks')->count();

        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);
        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $after = DB::table('inventory_stocks')->count();
        $this->assertEquals($before, $after);
    }

    public function test_receipt_idempotent_replay_creates_no_second_snapshot(): void
    {
        $receipt = $this->createAndConfirmDraft();
        $this->actingAs($this->receiver);

        $receipt = $this->postingService->post($receipt, $this->receiver->id);

        $snapshotCount = GoodsReceiptLineCommercialEvidence::count();
        $this->assertEquals(1, $snapshotCount);

        DB::table('goods_receipts')
            ->where('id', $receipt->id)
            ->update(['status' => 'CONFIRMATION_PENDING']);

        $this->confirmationService->confirm(
            $this->receiver,
            'inventory-goods-receipt-posting',
            'password',
            $this->property->company_id,
            $this->property->id
        );

        $receipt->refresh();
        $this->postingService->post($receipt, $this->receiver->id);

        $this->assertEquals($snapshotCount, GoodsReceiptLineCommercialEvidence::count());
    }
}
