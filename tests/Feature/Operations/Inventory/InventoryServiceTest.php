<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryAdjustmentLine;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryIssueLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryReceiptLine;
use Modules\Operations\Inventory\Models\InventoryStockBalance;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Services\AdjustmentService;
use Modules\Operations\Inventory\Services\IssueService;
use Modules\Operations\Inventory\Services\ReceiptService;
use Modules\Operations\Inventory\Services\TransferService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    private function bootProperty(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    private function makeCategory(Property $property): InventoryCategory
    {
        static $seq = 0;
        $seq++;
        return InventoryCategory::create([
            'property_id'   => $property->id,
            'category_code' => "CAT-{$seq}",
            'name'          => "Category {$seq}",
            'is_active'     => true,
        ]);
    }

    private function makeUnit(Property $property): InventoryUnit
    {
        static $seq = 0;
        $seq++;
        return InventoryUnit::create([
            'property_id'  => $property->id,
            'unit_code'    => "UNT-{$seq}",
            'abbreviation' => 'PCS',
            'name'         => "Unit {$seq}",
            'is_active'    => true,
        ]);
    }

    private function makeLocation(Property $property, string $type = LocationTypeEnum::MainStore->value): InventoryLocation
    {
        static $seq = 0;
        $seq++;
        return InventoryLocation::create([
            'property_id'   => $property->id,
            'location_code' => "LOC-{$seq}",
            'name'          => "Location {$seq}",
            'location_type' => $type,
            'is_active'     => true,
        ]);
    }

    private function makeItem(Property $property, InventoryCategory $category, InventoryUnit $unit): InventoryItem
    {
        static $seq = 0;
        $seq++;
        return InventoryItem::create([
            'property_id'  => $property->id,
            'item_code'    => "ITM-{$seq}",
            'name'         => "Item {$seq}",
            'category_id'  => $category->id,
            'unit_id'      => $unit->id,
            'is_active'    => true,
            'average_cost' => '0.0000',
        ]);
    }

    private function makeStockBalance(Property $property, InventoryItem $item, InventoryLocation $location, string $qty = '0.000'): InventoryStockBalance
    {
        return InventoryStockBalance::create([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => $qty,
            'status'      => (float)$qty > 0 ? ItemStatusEnum::InStock->value : ItemStatusEnum::OutOfStock->value,
        ]);
    }

    // --- Receipt Tests ---

    public function test_receipt_posting_updates_stock_and_wac()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit);

        // Initial stock: 10 qty at 5.00 cost -> WAC = 5.00
        $this->makeStockBalance($property, $item, $loc, '10.000');
        $item->update(['average_cost' => '5.0000']);

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-100',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '5.000',
            'unit_cost'   => '8.0000', // Received 5 at 8.00
            'total_value' => '40.0000',
        ]);

        $service = app(ReceiptService::class);
        $posted = $service->post($receipt->id, $admin->id);

        $this->assertEquals(ReceiptStatusEnum::Posted, $posted->status);

        $balance = InventoryStockBalance::where('item_id', $item->id)->where('location_id', $loc->id)->first();
        $this->assertEquals(15.000, (float) $balance->quantity);

        $updatedItem = $item->fresh();
        // Old Value: 10 * 5 = 50. New Value: 5 * 8 = 40. Total: 90 / 15 = 6.00
        $this->assertEquals(6.0000, (float) $updatedItem->average_cost);
    }

    // --- Issue Tests ---

    public function test_issue_posting_decreases_stock()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc, '20.000');

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-100',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        InventoryIssueLine::create([
            'property_id' => $property->id,
            'issue_id'    => $issue->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '5.000',
        ]);

        $service = app(IssueService::class);
        $posted = $service->post($issue->id, $admin->id);

        $this->assertEquals(IssueStatusEnum::Posted, $posted->status);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEquals(15.000, (float) $balance->quantity);
    }

    public function test_issue_blocks_negative_stock()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc, '5.000'); // Only 5 in stock

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-101',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        InventoryIssueLine::create([
            'property_id' => $property->id,
            'issue_id'    => $issue->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '10.000', // Requesting 10
        ]);

        $service = app(IssueService::class);
        
        $this->expectException(ValidationException::class);
        $service->post($issue->id, $admin->id);
    }

    // --- Transfer Tests ---

    public function test_transfer_completion()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc1 = $this->makeLocation($property);
        $loc2 = $this->makeLocation($property, LocationTypeEnum::MinibarStore->value);
        $item = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc1, '30.000');
        $this->makeStockBalance($property, $item, $loc2, '0.000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRN-100',
            'from_location_id' => $loc1->id,
            'to_location_id'   => $loc2->id,
            'status'           => TransferStatusEnum::Submitted->value,
            'requested_by'     => $admin->id,
        ]);

        InventoryTransferLine::create([
            'property_id'        => $property->id,
            'transfer_id'        => $transfer->id,
            'item_id'            => $item->id,
            'quantity_requested' => '10.000',
        ]);

        $service = app(TransferService::class);
        $completed = $service->complete($transfer->id, $admin->id);

        $this->assertEquals(TransferStatusEnum::Completed, $completed->status);

        $bal1 = InventoryStockBalance::where('item_id', $item->id)->where('location_id', $loc1->id)->first();
        $bal2 = InventoryStockBalance::where('item_id', $item->id)->where('location_id', $loc2->id)->first();

        $this->assertEquals(20.000, (float) $bal1->quantity);
        $this->assertEquals(10.000, (float) $bal2->quantity);
    }

    // --- Adjustment Tests ---

    public function test_adjustment_staleness_validation()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc, '10.000');

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-100',
            'location_id'       => $loc->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Submitted->value,
            'reason'            => 'Test reason',
        ]);

        InventoryAdjustmentLine::create([
            'property_id'       => $property->id,
            'adjustment_id'     => $adjustment->id,
            'item_id'           => $item->id,
            'quantity_system'   => '8.000', // Stale quantity! The balance is actually 10
            'quantity_actual'   => '12.000',
            'quantity_variance' => '4.000',
        ]);

        $service = app(AdjustmentService::class);

        $this->expectException(ValidationException::class);
        $service->approve($adjustment->id, $admin->id);
    }

    public function test_adjustment_approval_updates_stock()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc, '10.000');

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-101',
            'location_id'       => $loc->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Submitted->value,
            'reason'            => 'Test reason',
        ]);

        InventoryAdjustmentLine::create([
            'property_id'       => $property->id,
            'adjustment_id'     => $adjustment->id,
            'item_id'           => $item->id,
            'quantity_system'   => '10.000', // Correct system qty
            'quantity_actual'   => '12.000',
            'quantity_variance' => '2.000',
        ]);

        $service = app(AdjustmentService::class);
        $approved = $service->approve($adjustment->id, $admin->id);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $approved->status);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEquals(12.000, (float) $balance->quantity);
    }

    // --- Invalid Transitions ---

    public function test_invalid_transitions()
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-100',
            'status'         => ReceiptStatusEnum::Posted->value, // Already posted
        ]);

        $service = app(ReceiptService::class);

        $this->expectException(ValidationException::class);
        $service->post($receipt->id, $admin->id);
    }
}
