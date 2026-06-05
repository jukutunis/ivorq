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
use Modules\Operations\Inventory\Models\InventoryStockCard;
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function makeItem(
        Property $property,
        InventoryCategory $category,
        InventoryUnit $unit,
        float $averageCost = 0.0,
        float $reorderPoint = 0.0
    ): InventoryItem {
        static $seq = 0;
        $seq++;
        return InventoryItem::create([
            'property_id'   => $property->id,
            'item_code'     => "ITM-{$seq}",
            'name'          => "Item {$seq}",
            'category_id'   => $category->id,
            'unit_id'       => $unit->id,
            'is_active'     => true,
            'average_cost'  => number_format($averageCost, 4, '.', ''),
            'reorder_point' => number_format($reorderPoint, 3, '.', ''),
        ]);
    }

    private function makeStockBalance(
        Property $property,
        InventoryItem $item,
        InventoryLocation $location,
        string $qty = '0.000'
    ): InventoryStockBalance {
        return InventoryStockBalance::create([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => $qty,
            'status'      => (float) $qty > 0 ? ItemStatusEnum::InStock->value : ItemStatusEnum::OutOfStock->value,
        ]);
    }

    // =========================================================================
    // RECEIPT TESTS
    // =========================================================================

    /** Existing test — hardened assertions */
    public function test_receipt_posting_updates_stock_and_wac(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 5.0); // WAC 5.00

        // Initial stock: 10 qty at 5.00 cost → WAC = 5.00
        $this->makeStockBalance($property, $item, $loc, '10.000');

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
            'unit_cost'   => '8.0000',  // Received 5 at 8.00
            'total_value' => '40.0000',
        ]);

        $service = app(ReceiptService::class);
        $posted  = $service->post($receipt->id, $admin->id);

        // Status
        $this->assertEquals(ReceiptStatusEnum::Posted, $posted->status);

        // Balance
        $balance = InventoryStockBalance::where('item_id', $item->id)
            ->where('location_id', $loc->id)
            ->first();
        $this->assertEquals(15.000, (float) $balance->quantity);

        // WAC: (10*5 + 5*8) / 15 = 90 / 15 = 6.00
        $updatedItem = $item->fresh();
        $this->assertEqualsWithDelta(6.0000, (float) $updatedItem->average_cost, 0.0001);

        // Stock card assertions
        $card = InventoryStockCard::where('item_id', $item->id)->first();
        $this->assertNotNull($card);
        $this->assertEquals(TransactionTypeEnum::PurchaseReceipt, $card->movement_type);
        $this->assertEqualsWithDelta(10.000, (float) $card->quantity_before, 0.001);
        $this->assertEqualsWithDelta(5.000,  (float) $card->quantity_change,  0.001);
        $this->assertEqualsWithDelta(15.000, (float) $card->quantity_after,   0.001);
        $this->assertEqualsWithDelta(8.0000, (float) $card->unit_cost,        0.0001);
        $this->assertEqualsWithDelta(40.0000, (float) $card->total_value,     0.0001);
    }

    /** C-01 regression: multi-line same item must aggregate WAC, not loop-per-line */
    public function test_receipt_posting_multi_line_same_item_computes_wac_correctly(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc1 = $this->makeLocation($property);
        $loc2 = $this->makeLocation($property, LocationTypeEnum::MinibarStore->value);
        $item = $this->makeItem($property, $cat, $unit, 10.0); // WAC 10.00

        // 20 units on hand across two locations at WAC = 10.00
        $this->makeStockBalance($property, $item, $loc1, '10.000');
        $this->makeStockBalance($property, $item, $loc2, '10.000');

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-WAC-MULTI',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        // Line 1: 5 units at 20.00
        InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $loc1->id,
            'quantity'    => '5.000',
            'unit_cost'   => '20.0000',
            'total_value' => '100.0000',
        ]);

        // Line 2: 5 units at 30.00 (same item, different location)
        InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $loc2->id,
            'quantity'    => '5.000',
            'unit_cost'   => '30.0000',
            'total_value' => '150.0000',
        ]);

        $posted = app(ReceiptService::class)->post($receipt->id, $admin->id);

        $this->assertEquals(ReceiptStatusEnum::Posted, $posted->status);

        // Expected WAC:
        // old_qty=20, old_wac=10.00 → old_value=200
        // receipt: qty=10, value=5*20 + 5*30 = 250
        // new_wac = (200 + 250) / (20 + 10) = 450 / 30 = 15.00
        $updatedItem = $item->fresh();
        $this->assertEqualsWithDelta(15.0000, (float) $updatedItem->average_cost, 0.0001,
            'WAC must be computed from the aggregated receipt qty+value, not per-line.');
    }

    /** T-14: WAC when qty_on_hand = 0 at time of receipt */
    public function test_receipt_wac_when_starting_from_zero_stock(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 0.0); // WAC 0, no stock

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-ZERO',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '10.000',
            'unit_cost'   => '15.0000',
            'total_value' => '150.0000',
        ]);

        app(ReceiptService::class)->post($receipt->id, $admin->id);

        // new_wac = (0*0 + 10*15) / (0+10) = 15.00
        $this->assertEqualsWithDelta(15.0000, (float) $item->fresh()->average_cost, 0.0001);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEqualsWithDelta(10.000, (float) $balance->quantity, 0.001);
    }

    /** T-02: posting an empty receipt throws */
    public function test_receipt_post_rejects_zero_lines(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-EMPTY',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        $this->expectException(ValidationException::class);
        app(ReceiptService::class)->post($receipt->id, $admin->id);
    }

    // =========================================================================
    // ISSUE TESTS
    // =========================================================================

    /** Existing test — hardened assertions */
    public function test_issue_posting_decreases_stock(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 12.0); // WAC = 12.00

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

        $posted = app(IssueService::class)->post($issue->id, $admin->id);

        // Status
        $this->assertEquals(IssueStatusEnum::Posted, $posted->status);

        // Balance
        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEqualsWithDelta(15.000, (float) $balance->quantity, 0.001);

        // Stock card — BR-018: unit_cost = WAC at post time, total_value = qty * wac
        $card = InventoryStockCard::where('item_id', $item->id)->first();
        $this->assertEquals(TransactionTypeEnum::Issue, $card->movement_type);
        $this->assertEqualsWithDelta(20.000,  (float) $card->quantity_before, 0.001);
        $this->assertEqualsWithDelta(-5.000,  (float) $card->quantity_change,  0.001);
        $this->assertEqualsWithDelta(15.000,  (float) $card->quantity_after,   0.001);
        $this->assertEqualsWithDelta(12.0000, (float) $card->unit_cost,        0.0001);
        $this->assertEqualsWithDelta(-60.0000, (float) $card->total_value,     0.0001);
    }

    /** Existing test — unchanged, still passes */
    public function test_issue_blocks_negative_stock(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
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

        $this->expectException(ValidationException::class);
        app(IssueService::class)->post($issue->id, $admin->id);
    }

    /** T-03: posting an empty issue throws */
    public function test_issue_post_rejects_zero_lines(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-EMPTY',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        $this->expectException(ValidationException::class);
        app(IssueService::class)->post($issue->id, $admin->id);
    }

    /** T-11: issue against an inactive item throws */
    public function test_issue_against_inactive_item_throws(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit);

        // Deactivate the item (BR-012)
        $item->update(['is_active' => false]);
        $this->makeStockBalance($property, $item, $loc, '10.000');

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-INACTIVE',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        InventoryIssueLine::create([
            'property_id' => $property->id,
            'issue_id'    => $issue->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '2.000',
        ]);

        $this->expectException(ValidationException::class);
        app(IssueService::class)->post($issue->id, $admin->id);
    }

    // =========================================================================
    // TRANSFER TESTS
    // =========================================================================

    /** Existing test — hardened assertions */
    public function test_transfer_completion(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
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

        $completed = app(TransferService::class)->complete($transfer->id, $admin->id);

        $this->assertEquals(TransferStatusEnum::Completed, $completed->status);

        $bal1 = InventoryStockBalance::where('item_id', $item->id)->where('location_id', $loc1->id)->first();
        $bal2 = InventoryStockBalance::where('item_id', $item->id)->where('location_id', $loc2->id)->first();

        $this->assertEqualsWithDelta(20.000, (float) $bal1->quantity, 0.001);
        $this->assertEqualsWithDelta(10.000, (float) $bal2->quantity, 0.001);
    }

    /** T-04: completing a transfer with zero lines throws */
    public function test_transfer_complete_rejects_zero_lines(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $loc1 = $this->makeLocation($property);
        $loc2 = $this->makeLocation($property, LocationTypeEnum::MinibarStore->value);

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRN-EMPTY',
            'from_location_id' => $loc1->id,
            'to_location_id'   => $loc2->id,
            'status'           => TransferStatusEnum::Submitted->value,
            'requested_by'     => $admin->id,
        ]);

        $this->expectException(ValidationException::class);
        app(TransferService::class)->complete($transfer->id, $admin->id);
    }

    /** T-05: transfer blocks negative stock at source */
    public function test_transfer_completion_blocks_negative_stock_at_source(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc1 = $this->makeLocation($property);
        $loc2 = $this->makeLocation($property, LocationTypeEnum::MinibarStore->value);
        $item = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc1, '5.000'); // Only 5 available
        $this->makeStockBalance($property, $item, $loc2, '0.000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRN-NEGSTOCK',
            'from_location_id' => $loc1->id,
            'to_location_id'   => $loc2->id,
            'status'           => TransferStatusEnum::Submitted->value,
            'requested_by'     => $admin->id,
        ]);

        InventoryTransferLine::create([
            'property_id'        => $property->id,
            'transfer_id'        => $transfer->id,
            'item_id'            => $item->id,
            'quantity_requested' => '10.000', // Requesting more than available
        ]);

        $this->expectException(ValidationException::class);
        app(TransferService::class)->complete($transfer->id, $admin->id);
    }

    /** T-06: transfer stock cards carry null unit_cost and null total_value (BR-019) */
    public function test_transfer_stock_cards_have_null_cost(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc1 = $this->makeLocation($property);
        $loc2 = $this->makeLocation($property, LocationTypeEnum::MinibarStore->value);
        $item = $this->makeItem($property, $cat, $unit, 25.0); // Has a non-zero WAC

        $this->makeStockBalance($property, $item, $loc1, '10.000');
        $this->makeStockBalance($property, $item, $loc2, '0.000');

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRN-NULLCOST',
            'from_location_id' => $loc1->id,
            'to_location_id'   => $loc2->id,
            'status'           => TransferStatusEnum::Submitted->value,
            'requested_by'     => $admin->id,
        ]);

        InventoryTransferLine::create([
            'property_id'        => $property->id,
            'transfer_id'        => $transfer->id,
            'item_id'            => $item->id,
            'quantity_requested' => '5.000',
        ]);

        app(TransferService::class)->complete($transfer->id, $admin->id);

        $cards = InventoryStockCard::where('item_id', $item->id)->orderBy('quantity_change')->get();
        $this->assertCount(2, $cards); // one TransferOut, one TransferIn

        foreach ($cards as $card) {
            $this->assertNull($card->unit_cost,   "Transfer stock cards must have null unit_cost (BR-019)");
            $this->assertNull($card->total_value,  "Transfer stock cards must have null total_value (BR-019)");
        }

        $outCard = $cards->firstWhere('movement_type', TransactionTypeEnum::TransferOut);
        $inCard  = $cards->firstWhere('movement_type', TransactionTypeEnum::TransferIn);

        $this->assertNotNull($outCard);
        $this->assertNotNull($inCard);
        $this->assertEqualsWithDelta(-5.000, (float) $outCard->quantity_change, 0.001);
        $this->assertEqualsWithDelta(5.000,  (float) $inCard->quantity_change,  0.001);
    }

    // =========================================================================
    // ADJUSTMENT TESTS
    // =========================================================================

    /** Existing test — hardened assertions */
    public function test_adjustment_staleness_validation(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
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
            'quantity_system'   => '8.000',   // Stale — actual balance is 10
            'quantity_actual'   => '12.000',
            'quantity_variance' => '4.000',
        ]);

        $this->expectException(ValidationException::class);
        app(AdjustmentService::class)->approve($adjustment->id, $admin->id);
    }

    /** Existing test — hardened assertions */
    public function test_adjustment_approval_updates_stock(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 8.0); // WAC = 8.00

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
            'quantity_system'   => '10.000', // Correct snapshot
            'quantity_actual'   => '12.000',
            'quantity_variance' => '2.000',  // Positive → AdjustmentIn
        ]);

        $approved = app(AdjustmentService::class)->approve($adjustment->id, $admin->id);

        $this->assertEquals(AdjustmentStatusEnum::Approved, $approved->status);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEqualsWithDelta(12.000, (float) $balance->quantity, 0.001);

        // Stock card assertions (BR-067: unit_cost = WAC at approval time)
        $card = InventoryStockCard::where('item_id', $item->id)->first();
        $this->assertEquals(TransactionTypeEnum::AdjustmentIn, $card->movement_type);
        $this->assertEqualsWithDelta(10.000,  (float) $card->quantity_before, 0.001);
        $this->assertEqualsWithDelta(2.000,   (float) $card->quantity_change,  0.001);
        $this->assertEqualsWithDelta(12.000,  (float) $card->quantity_after,   0.001);
        $this->assertEqualsWithDelta(8.0000,  (float) $card->unit_cost,        0.0001);
        $this->assertEqualsWithDelta(16.0000, (float) $card->total_value,      0.0001);
    }

    /** T-09: negative variance creates AdjustmentOut stock card */
    public function test_adjustment_negative_variance_creates_adjustment_out_card(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 20.0); // WAC = 20.00

        $this->makeStockBalance($property, $item, $loc, '10.000');

        $adjustment = InventoryAdjustment::create([
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-NEG',
            'location_id'       => $loc->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Submitted->value,
            'reason'            => 'Negative variance test',
        ]);

        InventoryAdjustmentLine::create([
            'property_id'       => $property->id,
            'adjustment_id'     => $adjustment->id,
            'item_id'           => $item->id,
            'quantity_system'   => '10.000',
            'quantity_actual'   => '8.000',
            'quantity_variance' => '-2.000',  // Negative → AdjustmentOut
        ]);

        app(AdjustmentService::class)->approve($adjustment->id, $admin->id);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEqualsWithDelta(8.000, (float) $balance->quantity, 0.001);

        $card = InventoryStockCard::where('item_id', $item->id)->first();
        $this->assertEquals(TransactionTypeEnum::AdjustmentOut, $card->movement_type);
        $this->assertEqualsWithDelta(10.000,   (float) $card->quantity_before, 0.001);
        $this->assertEqualsWithDelta(-2.000,   (float) $card->quantity_change,  0.001);
        $this->assertEqualsWithDelta(8.000,    (float) $card->quantity_after,   0.001);
        $this->assertEqualsWithDelta(20.0000,  (float) $card->unit_cost,        0.0001);
        $this->assertEqualsWithDelta(-40.0000, (float) $card->total_value,      0.0001);
    }

    // =========================================================================
    // STATUS TESTS — BR-007 LOW STOCK
    // =========================================================================

    /** T-10: stock falling below reorder_point triggers low_stock status */
    public function test_low_stock_status_set_when_quantity_falls_below_reorder_point(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);

        // reorder_point = 5; we have 8 units; issue 5 → leaves 3 which is < 5 → low_stock
        $item = $this->makeItem($property, $cat, $unit, 10.0, 5.0);
        $this->makeStockBalance($property, $item, $loc, '8.000');

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-LOWSTOCK',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        InventoryIssueLine::create([
            'property_id' => $property->id,
            'issue_id'    => $issue->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '5.000', // leaves 3, below reorder_point of 5
        ]);

        app(IssueService::class)->post($issue->id, $admin->id);

        $balance = InventoryStockBalance::where('item_id', $item->id)->where('location_id', $loc->id)->first();
        $this->assertEqualsWithDelta(3.000, (float) $balance->quantity, 0.001);
        $this->assertEquals(ItemStatusEnum::LowStock, $balance->status,
            'Balance status must be low_stock when quantity is below reorder_point (BR-007).');
    }

    /** BR-007 out_of_stock: quantity reaches exactly 0 */
    public function test_out_of_stock_status_set_when_quantity_reaches_zero(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 5.0, 10.0);
        $this->makeStockBalance($property, $item, $loc, '5.000');

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-OOS',
            'status'       => IssueStatusEnum::Draft->value,
        ]);

        InventoryIssueLine::create([
            'property_id' => $property->id,
            'issue_id'    => $issue->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '5.000',
        ]);

        app(IssueService::class)->post($issue->id, $admin->id);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEquals(ItemStatusEnum::OutOfStock, $balance->status);
    }

    /** BR-007 in_stock: quantity at or above reorder_point */
    public function test_in_stock_status_set_when_quantity_meets_reorder_point(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $loc  = $this->makeLocation($property);
        $item = $this->makeItem($property, $cat, $unit, 0.0, 5.0); // reorder = 5

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-INSTOCK',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $property->id,
            'receipt_id'  => $receipt->id,
            'item_id'     => $item->id,
            'location_id' => $loc->id,
            'quantity'    => '10.000', // above reorder_point of 5
            'unit_cost'   => '5.0000',
            'total_value' => '50.0000',
        ]);

        app(ReceiptService::class)->post($receipt->id, $admin->id);

        $balance = InventoryStockBalance::where('item_id', $item->id)->first();
        $this->assertEquals(ItemStatusEnum::InStock, $balance->status);
    }

    // =========================================================================
    // INVALID TRANSITION TESTS
    // =========================================================================

    /** Existing test — unchanged */
    public function test_invalid_transitions(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $receipt = InventoryReceipt::create([
            'property_id'    => $property->id,
            'receipt_number' => 'RCT-100',
            'status'         => ReceiptStatusEnum::Posted->value, // Already posted
        ]);

        $this->expectException(ValidationException::class);
        app(ReceiptService::class)->post($receipt->id, $admin->id);
    }

    /** T-07: re-posting an already-posted Issue throws */
    public function test_invalid_transition_for_issue(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();

        $issue = InventoryIssue::create([
            'property_id'  => $property->id,
            'issue_number' => 'ISS-200',
            'status'       => IssueStatusEnum::Posted->value, // Already posted
        ]);

        $this->expectException(ValidationException::class);
        app(IssueService::class)->post($issue->id, $admin->id);
    }

    /** T-07: completing an already-completed Transfer throws */
    public function test_invalid_transition_for_transfer(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $loc1 = $this->makeLocation($property);
        $loc2 = $this->makeLocation($property, LocationTypeEnum::MinibarStore->value);

        $transfer = InventoryTransfer::create([
            'property_id'      => $property->id,
            'transfer_number'  => 'TRN-200',
            'from_location_id' => $loc1->id,
            'to_location_id'   => $loc2->id,
            'status'           => TransferStatusEnum::Completed->value, // Already done
            'requested_by'     => $admin->id,
        ]);

        $this->expectException(ValidationException::class);
        app(TransferService::class)->complete($transfer->id, $admin->id);
    }

    // =========================================================================
    // PROPERTY ISOLATION TESTS (T-13)
    // =========================================================================

    /**
     * Receipt posted under Property A must not be visible under Property B's
     * CurrentPropertyService context, and vice versa.
     */
    public function test_cross_property_service_isolation(): void
    {
        // Set up two independent properties
        $companyA   = $this->createCompany();
        $propertyA  = $this->createProperty($companyA);
        $adminA     = $this->createPropertyAdmin($propertyA);

        $companyB   = $this->createCompany();
        $propertyB  = $this->createProperty($companyB);
        $adminB     = $this->createPropertyAdmin($propertyB);

        // ---- Create data under Property A ----
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $catA  = $this->makeCategory($propertyA);
        $unitA = $this->makeUnit($propertyA);
        $locA  = $this->makeLocation($propertyA);
        $itemA = $this->makeItem($propertyA, $catA, $unitA);

        $receiptA = InventoryReceipt::create([
            'property_id'    => $propertyA->id,
            'receipt_number' => 'RCT-A001',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);

        InventoryReceiptLine::create([
            'property_id' => $propertyA->id,
            'receipt_id'  => $receiptA->id,
            'item_id'     => $itemA->id,
            'location_id' => $locA->id,
            'quantity'    => '10.000',
            'unit_cost'   => '5.0000',
            'total_value' => '50.0000',
        ]);

        app(ReceiptService::class)->post($receiptA->id, $adminA->id);

        // ---- Switch to Property B context ----
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        // Property B should not see Property A's receipt
        $receiptsUnderB = InventoryReceipt::all();
        $this->assertEmpty($receiptsUnderB, 'Property B must not see Property A\'s receipts (global scope isolation).');

        // Property B should not see Property A's stock balance
        $balancesUnderB = InventoryStockBalance::all();
        $this->assertEmpty($balancesUnderB, 'Property B must not see Property A\'s stock balances.');

        // Property B should not see Property A's stock cards
        $cardsUnderB = InventoryStockCard::all();
        $this->assertEmpty($cardsUnderB, 'Property B must not see Property A\'s stock cards.');
    }
}
