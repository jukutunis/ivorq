<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Http\Resources\InventoryAdjustmentLineResource;
use Modules\Operations\Inventory\Http\Resources\InventoryAdjustmentResource;
use Modules\Operations\Inventory\Http\Resources\InventoryCategoryResource;
use Modules\Operations\Inventory\Http\Resources\InventoryIssueLineResource;
use Modules\Operations\Inventory\Http\Resources\InventoryIssueResource;
use Modules\Operations\Inventory\Http\Resources\InventoryItemResource;
use Modules\Operations\Inventory\Http\Resources\InventoryLocationResource;
use Modules\Operations\Inventory\Http\Resources\InventoryReceiptLineResource;
use Modules\Operations\Inventory\Http\Resources\InventoryReceiptResource;
use Modules\Operations\Inventory\Http\Resources\InventoryStockBalanceResource;
use Modules\Operations\Inventory\Http\Resources\InventoryStockCardResource;
use Modules\Operations\Inventory\Http\Resources\InventoryTransferLineResource;
use Modules\Operations\Inventory\Http\Resources\InventoryTransferResource;
use Modules\Operations\Inventory\Http\Resources\InventoryUnitResource;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryStockBalance;
use Modules\Operations\Inventory\Models\InventoryStockCard;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesInventoryData;
use Tests\TestCase;

class InventoryResourceTest extends TestCase
{
    use RefreshDatabase, CreatesInventoryData;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function bootProperty(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('property', 'admin');
    }

    private function resolve(JsonResource $resource): array
    {
        return $resource->resolve(request());
    }

    private function assertHiddenFieldsAbsent(array $data): void
    {
        foreach (['created_by', 'updated_by', 'deleted_at'] as $field) {
            $this->assertArrayNotHasKey($field, $data,
                "Field '{$field}' must not be exposed in Inventory resources"
            );
        }
    }

    // ─── All 14 resources extend JsonResource ────────────────────────────────

    public function test_all_inventory_resource_classes_extend_json_resource(): void
    {
        $classes = [
            InventoryCategoryResource::class,
            InventoryUnitResource::class,
            InventoryLocationResource::class,
            InventoryItemResource::class,
            InventoryStockBalanceResource::class,
            InventoryStockCardResource::class,
            InventoryReceiptResource::class,
            InventoryReceiptLineResource::class,
            InventoryIssueResource::class,
            InventoryIssueLineResource::class,
            InventoryTransferResource::class,
            InventoryTransferLineResource::class,
            InventoryAdjustmentResource::class,
            InventoryAdjustmentLineResource::class,
        ];

        $this->assertCount(14, $classes);

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} must exist");
            $this->assertTrue(
                is_subclass_of($class, JsonResource::class),
                "{$class} must extend JsonResource"
            );
        }
    }

    // ─── InventoryCategoryResource ───────────────────────────────────────────

    public function test_category_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);

        $data = $this->resolve(new InventoryCategoryResource($category));

        $this->assertArrayHasKey('id',            $data);
        $this->assertArrayHasKey('property_id',   $data);
        $this->assertArrayHasKey('category_code', $data);
        $this->assertArrayHasKey('name',          $data);
        $this->assertArrayHasKey('is_active',     $data);
        $this->assertArrayHasKey('created_at',    $data);
        $this->assertArrayHasKey('updated_at',    $data);
        $this->assertSame($category->id,   $data['id']);
        $this->assertSame($category->name, $data['name']);
    }

    public function test_category_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $data = $this->resolve(new InventoryCategoryResource($this->makeInventoryCategory($property)));

        $this->assertHiddenFieldsAbsent($data);
    }

    // ─── InventoryUnitResource ───────────────────────────────────────────────

    public function test_unit_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $unit = $this->makeInventoryUnit($property);

        $data = $this->resolve(new InventoryUnitResource($unit));

        $this->assertArrayHasKey('id',           $data);
        $this->assertArrayHasKey('unit_code',    $data);
        $this->assertArrayHasKey('name',         $data);
        $this->assertArrayHasKey('abbreviation', $data);
        $this->assertArrayHasKey('is_active',    $data);
    }

    public function test_unit_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $data = $this->resolve(new InventoryUnitResource($this->makeInventoryUnit($property)));

        $this->assertHiddenFieldsAbsent($data);
    }

    // ─── InventoryLocationResource ───────────────────────────────────────────

    public function test_location_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeInventoryLocation($property);

        $data = $this->resolve(new InventoryLocationResource($location));

        $this->assertArrayHasKey('id',            $data);
        $this->assertArrayHasKey('location_code', $data);
        $this->assertArrayHasKey('name',          $data);
        $this->assertArrayHasKey('location_type', $data);
        $this->assertArrayHasKey('is_active',     $data);
    }

    public function test_location_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $data = $this->resolve(new InventoryLocationResource($this->makeInventoryLocation($property)));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_location_resource_location_type_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeInventoryLocation($property, ['location_type' => LocationTypeEnum::MainStore->value]);

        $data = $this->resolve(new InventoryLocationResource($location));

        $this->assertIsArray($data['location_type']);
        $this->assertArrayHasKey('value', $data['location_type']);
        $this->assertArrayHasKey('label', $data['location_type']);
        $this->assertSame(LocationTypeEnum::MainStore->value, $data['location_type']['value']);
        $this->assertSame(LocationTypeEnum::MainStore->label(), $data['location_type']['label']);
    }

    // ─── InventoryItemResource ───────────────────────────────────────────────

    public function test_item_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);

        $data = $this->resolve(new InventoryItemResource($item));

        $this->assertArrayHasKey('id',            $data);
        $this->assertArrayHasKey('item_code',     $data);
        $this->assertArrayHasKey('name',          $data);
        $this->assertArrayHasKey('category_id',   $data);
        $this->assertArrayHasKey('unit_id',       $data);
        $this->assertArrayHasKey('average_cost',  $data);
        $this->assertArrayHasKey('min_stock',     $data);
        $this->assertArrayHasKey('is_active',     $data);
    }

    public function test_item_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);

        $data = $this->resolve(new InventoryItemResource(
            $this->makeInventoryItem($property, $category, $unit)
        ));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_item_resource_average_cost_is_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit, ['average_cost' => 12.50]);

        $data = $this->resolve(new InventoryItemResource($item));

        $this->assertIsFloat($data['average_cost']);
        $this->assertSame(12.5, $data['average_cost']);
    }

    public function test_item_resource_decimal_stock_fields_are_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit, [
            'min_stock'        => 5.0,
            'max_stock'        => 100.0,
            'reorder_point'    => 10.0,
            'reorder_quantity' => 20.0,
        ]);

        $data = $this->resolve(new InventoryItemResource($item));

        $this->assertIsFloat($data['min_stock']);
        $this->assertIsFloat($data['max_stock']);
        $this->assertIsFloat($data['reorder_point']);
        $this->assertIsFloat($data['reorder_quantity']);
    }

    public function test_item_resource_nested_relations_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);

        // fresh load without with()
        $data = $this->resolve(new InventoryItemResource(
            \Modules\Operations\Inventory\Models\InventoryItem::find($item->id)
        ));

        $this->assertArrayNotHasKey('category',       $data);
        $this->assertArrayNotHasKey('unit',           $data);
        $this->assertArrayNotHasKey('stock_balances', $data);
    }

    public function test_item_resource_category_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = \Modules\Operations\Inventory\Models\InventoryItem::with('category')
            ->find($this->makeInventoryItem($property, $category, $unit)->id);

        $data = $this->resolve(new InventoryItemResource($item));

        $this->assertArrayHasKey('category', $data);
        $this->assertSame($category->id, $data['category']['id']);
    }

    // ─── InventoryStockBalanceResource ──────────────────────────────────────

    public function test_stock_balance_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $balance = InventoryStockBalance::create([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 50.0,
            'status'      => ItemStatusEnum::InStock->value,
        ]);

        $data = $this->resolve(new InventoryStockBalanceResource($balance));

        $this->assertArrayHasKey('id',               $data);
        $this->assertArrayHasKey('item_id',          $data);
        $this->assertArrayHasKey('location_id',      $data);
        $this->assertArrayHasKey('quantity',         $data);
        $this->assertArrayHasKey('status',           $data);
        $this->assertArrayHasKey('last_movement_at', $data);
    }

    public function test_stock_balance_resource_quantity_is_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $balance = InventoryStockBalance::create([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 42.5,
            'status'      => ItemStatusEnum::InStock->value,
        ]);

        $data = $this->resolve(new InventoryStockBalanceResource($balance));

        $this->assertIsFloat($data['quantity']);
        $this->assertSame(42.5, $data['quantity']);
    }

    public function test_stock_balance_resource_status_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $balance = InventoryStockBalance::create([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 5.0,
            'status'      => ItemStatusEnum::LowStock->value,
        ]);

        $data = $this->resolve(new InventoryStockBalanceResource($balance));

        $this->assertIsArray($data['status']);
        $this->assertSame(ItemStatusEnum::LowStock->value, $data['status']['value']);
        $this->assertSame(ItemStatusEnum::LowStock->label(), $data['status']['label']);
    }

    public function test_stock_balance_resource_relations_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $balance = InventoryStockBalance::find(InventoryStockBalance::create([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 1.0,
            'status'      => ItemStatusEnum::InStock->value,
        ])->id);

        $data = $this->resolve(new InventoryStockBalanceResource($balance));

        $this->assertArrayNotHasKey('item',     $data);
        $this->assertArrayNotHasKey('location', $data);
    }

    // ─── InventoryStockCardResource ──────────────────────────────────────────

    public function test_stock_card_resource_serializes_expected_fields(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        // StockCard is guarded — use DB::table / query builder
        $id = \Illuminate\Support\Str::ulid()->toBase32();
        \Illuminate\Support\Facades\DB::table('inventory_stock_cards')->insert([
            'id'              => $id,
            'property_id'     => $property->id,
            'item_id'         => $item->id,
            'location_id'     => $location->id,
            'movement_type'   => TransactionTypeEnum::PurchaseReceipt->value,
            'quantity_before' => 0,
            'quantity_change'  => 10,
            'quantity_after'   => 10,
            'unit_cost'       => 5.50,
            'total_value'     => 55.00,
            'reference_type'  => 'receipt',
            'reference_id'    => \Illuminate\Support\Str::ulid()->toBase32(),
            'posted_by'       => $admin->id,
            'posted_at'       => now()->toDateTimeString(),
        ]);

        $card = InventoryStockCard::find($id);
        $data = $this->resolve(new InventoryStockCardResource($card));

        $this->assertArrayHasKey('id',              $data);
        $this->assertArrayHasKey('item_id',         $data);
        $this->assertArrayHasKey('location_id',     $data);
        $this->assertArrayHasKey('movement_type',   $data);
        $this->assertArrayHasKey('quantity_before', $data);
        $this->assertArrayHasKey('quantity_change', $data);
        $this->assertArrayHasKey('quantity_after',  $data);
        $this->assertArrayHasKey('unit_cost',       $data);
        $this->assertArrayHasKey('total_value',     $data);
        $this->assertArrayHasKey('posted_at',       $data);
    }

    public function test_stock_card_resource_movement_type_enum_shape(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $id = \Illuminate\Support\Str::ulid()->toBase32();
        \Illuminate\Support\Facades\DB::table('inventory_stock_cards')->insert([
            'id'              => $id,
            'property_id'     => $property->id,
            'item_id'         => $item->id,
            'location_id'     => $location->id,
            'movement_type'   => TransactionTypeEnum::Issue->value,
            'quantity_before' => 10,
            'quantity_change'  => -3,
            'quantity_after'   => 7,
            'unit_cost'       => null,
            'total_value'     => null,
            'reference_type'  => 'issue',
            'reference_id'    => \Illuminate\Support\Str::ulid()->toBase32(),
            'posted_by'       => $admin->id,
            'posted_at'       => now()->toDateTimeString(),
        ]);

        $data = $this->resolve(new InventoryStockCardResource(InventoryStockCard::find($id)));

        $this->assertIsArray($data['movement_type']);
        $this->assertSame(TransactionTypeEnum::Issue->value, $data['movement_type']['value']);
        $this->assertSame(TransactionTypeEnum::Issue->label(), $data['movement_type']['label']);
    }

    public function test_stock_card_resource_decimal_fields_are_float(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $id = \Illuminate\Support\Str::ulid()->toBase32();
        \Illuminate\Support\Facades\DB::table('inventory_stock_cards')->insert([
            'id'              => $id,
            'property_id'     => $property->id,
            'item_id'         => $item->id,
            'location_id'     => $location->id,
            'movement_type'   => TransactionTypeEnum::PurchaseReceipt->value,
            'quantity_before' => 0,
            'quantity_change'  => 5.25,
            'quantity_after'   => 5.25,
            'unit_cost'       => 3.75,
            'total_value'     => 19.69,
            'reference_type'  => 'receipt',
            'reference_id'    => \Illuminate\Support\Str::ulid()->toBase32(),
            'posted_by'       => $admin->id,
            'posted_at'       => now()->toDateTimeString(),
        ]);

        $data = $this->resolve(new InventoryStockCardResource(InventoryStockCard::find($id)));

        $this->assertIsFloat($data['quantity_change']);
        $this->assertIsFloat($data['unit_cost']);
        $this->assertIsFloat($data['total_value']);
    }

    public function test_stock_card_resource_relations_absent_when_not_loaded(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $id = \Illuminate\Support\Str::ulid()->toBase32();
        \Illuminate\Support\Facades\DB::table('inventory_stock_cards')->insert([
            'id'              => $id,
            'property_id'     => $property->id,
            'item_id'         => $item->id,
            'location_id'     => $location->id,
            'movement_type'   => TransactionTypeEnum::PurchaseReceipt->value,
            'quantity_before' => 0,
            'quantity_change'  => 1,
            'quantity_after'   => 1,
            'unit_cost'       => null,
            'total_value'     => null,
            'reference_type'  => 'receipt',
            'reference_id'    => \Illuminate\Support\Str::ulid()->toBase32(),
            'posted_by'       => $admin->id,
            'posted_at'       => now()->toDateTimeString(),
        ]);

        $data = $this->resolve(new InventoryStockCardResource(InventoryStockCard::find($id)));

        $this->assertArrayNotHasKey('item',           $data);
        $this->assertArrayNotHasKey('location',       $data);
        $this->assertArrayNotHasKey('posted_by_user', $data);
    }

    // ─── InventoryReceiptResource ────────────────────────────────────────────

    public function test_receipt_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-001',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        $data = $this->resolve(new InventoryReceiptResource($receipt));

        $this->assertArrayHasKey('id',             $data);
        $this->assertArrayHasKey('receipt_number', $data);
        $this->assertArrayHasKey('status',         $data);
        $this->assertArrayHasKey('posted_by',      $data);
        $this->assertArrayHasKey('posted_at',      $data);
        $this->assertArrayHasKey('cancelled_by',   $data);
        $this->assertArrayHasKey('cancelled_at',   $data);
        $this->assertArrayHasKey('created_at',     $data);
        $this->assertArrayHasKey('updated_at',     $data);
    }

    public function test_receipt_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-HIDE',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        $data = $this->resolve(new InventoryReceiptResource($receipt));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_receipt_resource_status_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-ENUM',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        $data = $this->resolve(new InventoryReceiptResource($receipt));

        $this->assertIsArray($data['status']);
        $this->assertSame(ReceiptStatusEnum::Draft->value, $data['status']['value']);
        $this->assertSame(ReceiptStatusEnum::Draft->label(), $data['status']['label']);
    }

    public function test_receipt_resource_lines_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();

        $receipt = InventoryReceipt::find(InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-NL',
            'status'         => ReceiptStatusEnum::Draft->value,
        ])->id);

        $data = $this->resolve(new InventoryReceiptResource($receipt));

        $this->assertArrayNotHasKey('lines', $data);
    }

    public function test_receipt_resource_lines_present_when_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-L1',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 10,
            'unit_cost'   => 5.00,
            'total_value' => 50.00,
        ]);

        $receipt = InventoryReceipt::with('lines')->find($receipt->id);
        $data    = $this->resolve(new InventoryReceiptResource($receipt));

        $this->assertArrayHasKey('lines', $data);
        $this->assertCount(1, $data['lines']);
    }

    // ─── InventoryReceiptLineResource ────────────────────────────────────────

    public function test_receipt_line_resource_decimal_fields_are_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-DEC',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        $line = InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 3.5,
            'unit_cost'   => 7.25,
            'total_value' => 25.375,
        ]);

        $data = $this->resolve(new InventoryReceiptLineResource($line));

        $this->assertIsFloat($data['quantity']);
        $this->assertIsFloat($data['unit_cost']);
        $this->assertIsFloat($data['total_value']);
        $this->assertSame(3.5,    $data['quantity']);
        $this->assertSame(7.25,   $data['unit_cost']);
    }

    public function test_receipt_line_resource_relations_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-RNL',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        $line = InventoryReceiptLine::find(InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 1,
            'unit_cost'   => 1,
            'total_value' => 1,
        ])->id);

        $data = $this->resolve(new InventoryReceiptLineResource($line));

        $this->assertArrayNotHasKey('item',     $data);
        $this->assertArrayNotHasKey('location', $data);
    }

    // ─── InventoryIssueResource ──────────────────────────────────────────────

    public function test_issue_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-001',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        $data = $this->resolve(new InventoryIssueResource($issue));

        $this->assertArrayHasKey('id',           $data);
        $this->assertArrayHasKey('issue_number', $data);
        $this->assertArrayHasKey('status',       $data);
        $this->assertArrayHasKey('posted_by',    $data);
        $this->assertArrayHasKey('posted_at',    $data);
        $this->assertArrayHasKey('cancelled_by', $data);
        $this->assertArrayHasKey('cancelled_at', $data);
    }

    public function test_issue_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-HIDE',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        $data = $this->resolve(new InventoryIssueResource($issue));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_issue_resource_status_enum_shape(): void
    {
        ['property' => $property] = $this->bootProperty();

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-ENUM',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        $data = $this->resolve(new InventoryIssueResource($issue));

        $this->assertIsArray($data['status']);
        $this->assertSame(IssueStatusEnum::Draft->value,  $data['status']['value']);
        $this->assertSame(IssueStatusEnum::Draft->label(), $data['status']['label']);
    }

    public function test_issue_resource_lines_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();

        $issue = InventoryIssue::find(InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-NL',
            'status'       => IssueStatusEnum::Draft->value,
        ])->id);

        $data = $this->resolve(new InventoryIssueResource($issue));

        $this->assertArrayNotHasKey('lines', $data);
    }

    public function test_issue_line_resource_quantity_is_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-QTY',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        $line = InventoryIssueLine::create([
            'property_id' => $property->id,
            'issue_id'    => $issue->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => 7.5,
        ]);

        $data = $this->resolve(new InventoryIssueLineResource($line));

        $this->assertIsFloat($data['quantity']);
        $this->assertSame(7.5, $data['quantity']);
    }

    // ─── InventoryTransferResource ───────────────────────────────────────────

    public function test_transfer_resource_serializes_expected_fields(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $locA = $this->makeInventoryLocation($property, ['location_code' => 'LOC-A']);
        $locB = $this->makeInventoryLocation($property, ['location_code' => 'LOC-B']);

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRF-001',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
            'status'           => TransferStatusEnum::Draft->value,
            'requested_by'     => $admin->id,
        ]);

        $data = $this->resolve(new InventoryTransferResource($transfer));

        $this->assertArrayHasKey('id',                $data);
        $this->assertArrayHasKey('transfer_number',   $data);
        $this->assertArrayHasKey('from_location_id',  $data);
        $this->assertArrayHasKey('to_location_id',    $data);
        $this->assertArrayHasKey('status',            $data);
        $this->assertArrayHasKey('approved_by',       $data);
        $this->assertArrayHasKey('completed_by',      $data);
        $this->assertArrayHasKey('cancelled_by',      $data);
    }

    public function test_transfer_resource_hides_audit_fields(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $locA = $this->makeInventoryLocation($property, ['location_code' => 'LOC-HA']);
        $locB = $this->makeInventoryLocation($property, ['location_code' => 'LOC-HB']);

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRF-HIDE',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
            'status'           => TransferStatusEnum::Draft->value,
            'requested_by'     => $admin->id,
        ]);

        $data = $this->resolve(new InventoryTransferResource($transfer));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_transfer_resource_status_enum_shape(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $locA = $this->makeInventoryLocation($property, ['location_code' => 'LOC-EA']);
        $locB = $this->makeInventoryLocation($property, ['location_code' => 'LOC-EB']);

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRF-ENUM',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
            'status'           => TransferStatusEnum::Draft->value,
            'requested_by'     => $admin->id,
        ]);

        $data = $this->resolve(new InventoryTransferResource($transfer));

        $this->assertIsArray($data['status']);
        $this->assertSame(TransferStatusEnum::Draft->value,  $data['status']['value']);
        $this->assertSame(TransferStatusEnum::Draft->label(), $data['status']['label']);
    }

    public function test_transfer_resource_lines_absent_when_not_loaded(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $locA = $this->makeInventoryLocation($property, ['location_code' => 'LOC-NA']);
        $locB = $this->makeInventoryLocation($property, ['location_code' => 'LOC-NB']);

        $transfer = InventoryTransfer::find(InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRF-NL',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
            'status'           => TransferStatusEnum::Draft->value,
            'requested_by'     => $admin->id,
        ])->id);

        $data = $this->resolve(new InventoryTransferResource($transfer));

        $this->assertArrayNotHasKey('lines',        $data);
        $this->assertArrayNotHasKey('from_location', $data);
        $this->assertArrayNotHasKey('to_location',  $data);
    }

    public function test_transfer_line_resource_quantity_requested_is_float(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $locA     = $this->makeInventoryLocation($property, ['location_code' => 'LOC-TLA']);
        $locB     = $this->makeInventoryLocation($property, ['location_code' => 'LOC-TLB']);

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRF-QTY',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
            'status'           => TransferStatusEnum::Draft->value,
            'requested_by'     => $admin->id,
        ]);

        $line = InventoryTransferLine::create([
            'property_id'        => $property->id,
            'transfer_id'        => $transfer->id,
            'item_id'            => $item->id,
            'quantity_requested' => 4.25,
        ]);

        $data = $this->resolve(new InventoryTransferLineResource($line));

        $this->assertIsFloat($data['quantity_requested']);
        $this->assertSame(4.25, $data['quantity_requested']);
    }

    // ─── InventoryAdjustmentResource ─────────────────────────────────────────

    public function test_adjustment_resource_serializes_expected_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeInventoryLocation($property);

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-001',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Draft->value,
            'reason'            => 'Annual count',
        ]);

        $data = $this->resolve(new InventoryAdjustmentResource($adjustment));

        $this->assertArrayHasKey('id',                $data);
        $this->assertArrayHasKey('adjustment_number', $data);
        $this->assertArrayHasKey('location_id',       $data);
        $this->assertArrayHasKey('adjustment_type',   $data);
        $this->assertArrayHasKey('status',            $data);
        $this->assertArrayHasKey('reason',            $data);
        $this->assertArrayHasKey('submitted_by',      $data);
        $this->assertArrayHasKey('approved_by',       $data);
        $this->assertArrayHasKey('rejected_by',       $data);
        $this->assertArrayHasKey('rejection_reason',  $data);
    }

    public function test_adjustment_resource_hides_audit_fields(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeInventoryLocation($property, ['location_code' => 'LOC-AH']);

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-HIDE',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Draft->value,
            'reason'            => 'Test',
        ]);

        $data = $this->resolve(new InventoryAdjustmentResource($adjustment));

        $this->assertHiddenFieldsAbsent($data);
    }

    public function test_adjustment_resource_enum_shapes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeInventoryLocation($property, ['location_code' => 'LOC-AE']);

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-ENUM',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::Damaged->value,
            'status'            => AdjustmentStatusEnum::Submitted->value,
            'reason'            => 'Damaged goods',
        ]);

        $data = $this->resolve(new InventoryAdjustmentResource($adjustment));

        $this->assertIsArray($data['adjustment_type']);
        $this->assertSame(AdjustmentTypeEnum::Damaged->value,  $data['adjustment_type']['value']);
        $this->assertSame(AdjustmentTypeEnum::Damaged->label(), $data['adjustment_type']['label']);

        $this->assertIsArray($data['status']);
        $this->assertSame(AdjustmentStatusEnum::Submitted->value,  $data['status']['value']);
        $this->assertSame(AdjustmentStatusEnum::Submitted->label(), $data['status']['label']);
    }

    public function test_adjustment_resource_lines_absent_when_not_loaded(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeInventoryLocation($property, ['location_code' => 'LOC-ANL']);

        $adjustment = InventoryAdjustment::find(InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-NL',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Draft->value,
            'reason'            => 'Test',
        ])->id);

        $data = $this->resolve(new InventoryAdjustmentResource($adjustment));

        $this->assertArrayNotHasKey('lines',    $data);
        $this->assertArrayNotHasKey('location', $data);
    }

    public function test_adjustment_line_resource_decimal_fields_are_float(): void
    {
        ['property' => $property] = $this->bootProperty();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property, ['location_code' => 'LOC-ALD']);

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-DEC',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Draft->value,
            'reason'            => 'Test',
        ]);

        $line = InventoryAdjustmentLine::create([
            'property_id'       => $property->id,
            'adjustment_id'     => $adjustment->id,
            'item_id'           => $item->id,
            'quantity_system'   => 10.0,
            'quantity_actual'   => 8.5,
            'quantity_variance' => -1.5,
            'unit_cost'         => 4.25,
        ]);

        $data = $this->resolve(new InventoryAdjustmentLineResource($line));

        $this->assertIsFloat($data['quantity_system']);
        $this->assertIsFloat($data['quantity_actual']);
        $this->assertIsFloat($data['quantity_variance']);
        $this->assertIsFloat($data['unit_cost']);
        $this->assertSame(10.0, $data['quantity_system']);
        $this->assertSame(8.5,  $data['quantity_actual']);
        $this->assertSame(-1.5, $data['quantity_variance']);
        $this->assertSame(4.25, $data['unit_cost']);
    }
}
