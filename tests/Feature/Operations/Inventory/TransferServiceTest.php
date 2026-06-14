<?php

namespace Tests\Feature\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Operations\Inventory\Services\TransferService;
use Modules\Foundation\Property\Models\Property;
use Tests\TestCase;

class TransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransferService $service;
    private Property $property;
    private InventoryItem $item;
    private InventoryLocation $fromLocation;
    private InventoryLocation $toLocation;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $this->service = app(TransferService::class);
        
        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'General']);
        $uom = \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Pieces', 'code' => 'PCS']);
        
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-TRNS-1',
            'name' => 'Transfer Test Item',
            'inventory_type' => 'goods',
            'is_active' => true,
            'reorder_point' => 10,
            'weighted_average_cost' => 10.00
        ]);
        
        $this->fromLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Store A',
            'type' => 'internal',
        ]);

        $this->toLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Store B',
            'type' => 'internal',
        ]);
    }

    public function test_transfer_complete_generates_out_and_in_ledgers()
    {
        // Setup initial stock in source location
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'location_id' => $this->fromLocation->id,
            'physical_quantity' => 15,
            'reserved_quantity' => 0,
        ]);

        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'TRF-001',
            'status' => TransferStatusEnum::Submitted,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item->id,
            'quantity_requested' => 5,
        ]);

        $this->service->complete($transfer->id);

        $transfer->refresh();
        $this->assertEquals(TransferStatusEnum::Completed, $transfer->status);

        // Assert Stocks
        $fromStock = InventoryStock::where('location_id', $this->fromLocation->id)->first();
        $this->assertEquals(10, (float) $fromStock->physical_quantity);

        $toStock = InventoryStock::where('location_id', $this->toLocation->id)->first();
        $this->assertEquals(5, (float) $toStock->physical_quantity);

        // Assert Ledgers
        $transactions = InventoryTransaction::where('reference_id', $transfer->id)->get();
        $this->assertCount(2, $transactions);

        $outTransaction = $transactions->where('transaction_type', TransactionTypeEnum::TransferOut)->first();
        $this->assertNotNull($outTransaction);
        $this->assertEquals(-5, (float) $outTransaction->quantity_change);
        $this->assertEquals($this->fromLocation->id, $outTransaction->location_id);

        $inTransaction = $transactions->where('transaction_type', TransactionTypeEnum::TransferIn)->first();
        $this->assertNotNull($inTransaction);
        $this->assertEquals(5, (float) $inTransaction->quantity_change);
        $this->assertEquals($this->toLocation->id, $inTransaction->location_id);
    }
}
