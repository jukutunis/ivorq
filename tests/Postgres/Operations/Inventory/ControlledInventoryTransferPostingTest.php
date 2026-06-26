<?php

namespace Tests\Postgres\Operations\Inventory;

use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryTransferLine;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Shared\Services\CurrentPropertyService;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Services\TransferService;
use Illuminate\Validation\ValidationException;
use Shared\Exceptions\BusinessLogicException;

class ControlledInventoryTransferPostingTest extends PostgresTestCase
{
    use RefreshDatabase;
    protected $seed = true;

    private Property $property;
    private User $user;
    private InventoryItem $item1;
    private InventoryItem $item2;
    private InventoryLocation $fromLocation;
    private InventoryLocation $toLocation;
    private TransferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $this->user = User::first();
        $this->actingAs($this->user);

        // Open Business Date and Financial Period
        PropertyBusinessDate::updateOrCreate(
            ['property_id' => $this->property->id, 'business_date' => now()->toDateString()],
            [
                'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'opened_at' => now(),
                'opened_by' => $this->user->id
            ]
        );

        FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => now()->year, 'period_month' => now()->month],
            [
                'status' => \Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum::Open,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth()
            ]
        );

        $invCategory = \Modules\Operations\Inventory\Models\InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'General'
        ]);

        $this->item1 = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-TRF-001',
            'name' => 'Transfer Item 1',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 10.00,
            'is_active' => true,
        ]);

        $this->item2 = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $invCategory->id,
            'sku' => 'ITM-TRF-002',
            'name' => 'Transfer Item 2',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 20.00,
            'is_active' => true,
        ]);

        $this->fromLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'From Store',
            'type' => 'internal',
        ]);

        $this->toLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'To Store',
            'type' => 'internal',
        ]);

        // Seed initial physical quantity of 100 for Item 1 and 10 for Item 2 in source location
        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item1->id,
            'location_id' => $this->fromLocation->id,
            'physical_quantity' => 100,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        InventoryStock::create([
            'property_id' => $this->property->id,
            'item_id' => $this->item2->id,
            'location_id' => $this->fromLocation->id,
            'physical_quantity' => 10,
            'status' => \Modules\Operations\Inventory\Enums\ItemStatusEnum::InStock,
        ]);

        $this->service = app(TransferService::class);
    }

    public function test_successful_controlled_transfer(): void
    {
        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'TRF-OK-001',
            'status' => TransferStatusEnum::Submitted->value,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item1->id,
            'quantity_requested' => 15,
        ]);

        $this->service->complete($transfer->id);

        $this->assertEquals(TransferStatusEnum::Completed, $transfer->fresh()->status);
        $this->assertEquals($this->user->id, $transfer->fresh()->completed_by);

        // Verify stock card mutations
        $stockFrom = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->fromLocation->id)->first();
        $this->assertEquals(85, $stockFrom->physical_quantity);

        $stockTo = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->toLocation->id)->first();
        $this->assertEquals(15, $stockTo->physical_quantity);

        // Verify ledgers created
        $txs = InventoryTransaction::where('source_document_id', $transfer->id)->get();
        $this->assertCount(2, $txs);

        $outTx = $txs->where('transaction_type', TransactionTypeEnum::TransferOut->value)->first();
        $this->assertNotNull($outTx);
        $this->assertEquals(-15, $outTx->quantity_change);
        $this->assertEquals(10.00, (float) $outTx->unit_cost);
        $this->assertEquals(-150.00, (float) $outTx->total_cost);
        $this->assertEquals($this->fromLocation->id, $outTx->location_id);
        $this->assertEquals($this->user->id, $outTx->posted_by);

        $inTx = $txs->where('transaction_type', TransactionTypeEnum::TransferIn->value)->first();
        $this->assertNotNull($inTx);
        $this->assertEquals(15, $inTx->quantity_change);
        $this->assertEquals(10.00, (float) $inTx->unit_cost);
        $this->assertEquals(150.00, (float) $inTx->total_cost);
        $this->assertEquals($this->toLocation->id, $inTx->location_id);
        $this->assertEquals($this->user->id, $inTx->posted_by);
    }

    public function test_idempotent_repeat_protection(): void
    {
        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'TRF-IDEM-001',
            'status' => TransferStatusEnum::Submitted->value,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item1->id,
            'quantity_requested' => 20,
        ]);

        $this->service->complete($transfer->id);

        $stockFromBefore = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->fromLocation->id)->first()->physical_quantity;
        $stockToBefore = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->toLocation->id)->first()->physical_quantity;
        $txCountBefore = InventoryTransaction::where('source_document_id', $transfer->id)->count();

        // Reset state back to submitted to simulate re-posting
        $transfer->refresh()->update(['status' => TransferStatusEnum::Submitted->value]);

        $this->service->complete($transfer->id);

        $stockFromAfter = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->fromLocation->id)->first()->physical_quantity;
        $stockToAfter = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->toLocation->id)->first()->physical_quantity;
        $txCountAfter = InventoryTransaction::where('source_document_id', $transfer->id)->count();

        $this->assertEquals($stockFromBefore, $stockFromAfter);
        $this->assertEquals($stockToBefore, $stockToAfter);
        $this->assertEquals($txCountBefore, $txCountAfter);
        $this->assertEquals(TransferStatusEnum::Completed, $transfer->fresh()->status);
    }

    public function test_closed_business_date_rejects_transfer(): void
    {
        // Close the business date
        PropertyBusinessDate::where('property_id', $this->property->id)
            ->update([
                'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Closed,
                'is_open' => null
            ]);

        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'TRF-CLOSED-BD',
            'status' => TransferStatusEnum::Submitted->value,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item1->id,
            'quantity_requested' => 10,
        ]);

        $this->expectException(\Throwable::class);

        try {
            $this->service->complete($transfer->id);
        } finally {
            $this->assertEquals(TransferStatusEnum::Submitted, $transfer->fresh()->status);
            $stockFrom = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->fromLocation->id)->first()->physical_quantity;
            $this->assertEquals(100, $stockFrom);
            $this->assertCount(0, InventoryTransaction::where('source_document_id', $transfer->id)->get());
        }
    }

    public function test_failure_atomicity_rolls_back_entire_transfer(): void
    {
        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'TRF-ATOM-001',
            'status' => TransferStatusEnum::Submitted->value,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        // Line 1 is valid (5 units requested vs 100 available)
        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item1->id,
            'quantity_requested' => 5,
        ]);

        // Line 2 is invalid (20 units requested vs 10 available)
        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item2->id,
            'quantity_requested' => 20,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Negative stock is not allowed/');

        try {
            $this->service->complete($transfer->id);
        } finally {
            $this->assertEquals(TransferStatusEnum::Submitted, $transfer->fresh()->status);

            // Assert absolute rollback: item1 should remain at 100 in fromLocation and 0 in toLocation
            $stock1From = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->fromLocation->id)->first()->physical_quantity;
            $stock1To = InventoryStock::where('item_id', $this->item1->id)->where('location_id', $this->toLocation->id)->first()?->physical_quantity ?? 0;
            $this->assertEquals(100, $stock1From);
            $this->assertEquals(0, $stock1To);

            // Item2 remains at 10 in fromLocation and 0 in toLocation
            $stock2From = InventoryStock::where('item_id', $this->item2->id)->where('location_id', $this->fromLocation->id)->first()->physical_quantity;
            $stock2To = InventoryStock::where('item_id', $this->item2->id)->where('location_id', $this->toLocation->id)->first()?->physical_quantity ?? 0;
            $this->assertEquals(10, $stock2From);
            $this->assertEquals(0, $stock2To);

            $this->assertCount(0, InventoryTransaction::where('source_document_id', $transfer->id)->get());
        }
    }

    public function test_actor_compatibility_complete_with_caller_supplied_user(): void
    {
        auth()->logout();

        $transfer = InventoryTransfer::create([
            'property_id' => $this->property->id,
            'transfer_number' => 'TRF-ACTOR-001',
            'status' => TransferStatusEnum::Submitted->value,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        InventoryTransferLine::create([
            'property_id' => $this->property->id,
            'transfer_id' => $transfer->id,
            'item_id' => $this->item1->id,
            'quantity_requested' => 10,
        ]);

        $externalActorId = (string) \Illuminate\Support\Str::ulid();

        $this->service->complete($transfer->id, $externalActorId);

        $this->assertEquals(TransferStatusEnum::Completed, $transfer->fresh()->status);
        $this->assertEquals($externalActorId, $transfer->fresh()->completed_by);

        $txs = InventoryTransaction::where('source_document_id', $transfer->id)->get();
        $this->assertCount(2, $txs);
        foreach ($txs as $tx) {
            $this->assertEquals($externalActorId, $tx->posted_by);
        }
    }
}
