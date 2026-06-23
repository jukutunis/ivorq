<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Repositories\InventoryStockRepository;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Enums\ItemStatusEnum;

class InventoryStockControlledLockTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_controlled_stock_create_or_lock_behavior_works_for_missing_row(): void
    {
        $repository = new InventoryStockRepository();

        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();

        DB::transaction(function () use ($repository, $propertyId, $itemId, $locationId) {
            $stock = $repository->createOrLockControlled($propertyId, $itemId, $locationId);

            $this->assertNotNull($stock);
            $this->assertEquals($propertyId, $stock->property_id);
            $this->assertEquals($itemId, $stock->item_id);
            $this->assertEquals($locationId, $stock->location_id);
            $this->assertEquals(0, $stock->physical_quantity);
            $this->assertSame(ItemStatusEnum::OutOfStock, $stock->status);
        });
    }

    public function test_controlled_stock_create_or_lock_behavior_works_for_existing_row(): void
    {
        $repository = new InventoryStockRepository();

        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();

        InventoryStock::create([
            'property_id' => $propertyId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'physical_quantity' => 15,
            'reserved_quantity' => 0,
            'status' => ItemStatusEnum::InStock->value,
        ]);

        DB::transaction(function () use ($repository, $propertyId, $itemId, $locationId) {
            $stock = $repository->createOrLockControlled($propertyId, $itemId, $locationId);

            $this->assertNotNull($stock);
            $this->assertEquals($propertyId, $stock->property_id);
            $this->assertEquals($itemId, $stock->item_id);
            $this->assertEquals($locationId, $stock->location_id);
            $this->assertEquals(15, $stock->physical_quantity);
            $this->assertSame(ItemStatusEnum::InStock, $stock->status);
        });
    }
    public function test_repeated_controlled_resolution_returns_the_same_tuple_row(): void
    {
        $repository = new InventoryStockRepository();

        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();

        DB::transaction(function () use ($repository, $propertyId, $itemId, $locationId) {
            $stock1 = $repository->createOrLockControlled($propertyId, $itemId, $locationId);
            $stock2 = $repository->createOrLockControlled($propertyId, $itemId, $locationId);

            $this->assertEquals($stock1->id, $stock2->id);
            $this->assertEquals(1, InventoryStock::where('property_id', $propertyId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->count());
        });
    }
}
