<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Department\Models\Department;
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
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryStockBalance;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Repositories\InventoryAdjustmentRepository;
use Modules\Operations\Inventory\Repositories\InventoryCategoryRepository;
use Modules\Operations\Inventory\Repositories\InventoryIssueRepository;
use Modules\Operations\Inventory\Repositories\InventoryItemRepository;
use Modules\Operations\Inventory\Repositories\InventoryLocationRepository;
use Modules\Operations\Inventory\Repositories\InventoryReceiptRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockBalanceRepository;
use Modules\Operations\Inventory\Repositories\InventoryStockCardRepository;
use Modules\Operations\Inventory\Repositories\InventoryTransferRepository;
use Modules\Operations\Inventory\Repositories\InventoryUnitRepository;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class InventoryRepositoryTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ─────────────────────────────────────────────────────────────────────────
    // Boot helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bootProperty(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    private function makeCategory(Property $property, array $overrides = []): InventoryCategory
    {
        static $seq = 0;
        $seq++;

        return InventoryCategory::create(array_merge([
            'property_id'   => $property->id,
            'category_code' => "CAT-{$seq}",
            'name'          => "Category {$seq}",
            'is_active'     => true,
        ], $overrides));
    }

    private function makeUnit(Property $property, array $overrides = []): InventoryUnit
    {
        static $seq = 0;
        $seq++;

        return InventoryUnit::create(array_merge([
            'property_id'  => $property->id,
            'unit_code'    => "UNT-{$seq}",
            'abbreviation' => 'PCS',
            'name'         => "Unit {$seq}",
            'is_active'    => true,
        ], $overrides));
    }

    private function makeLocation(Property $property, array $overrides = []): InventoryLocation
    {
        static $seq = 0;
        $seq++;

        return InventoryLocation::create(array_merge([
            'property_id'   => $property->id,
            'location_code' => "LOC-{$seq}",
            'name'          => "Location {$seq}",
            'location_type' => LocationTypeEnum::MainStore->value,
            'is_active'     => true,
        ], $overrides));
    }

    private function makeItem(
        Property         $property,
        InventoryCategory $category,
        InventoryUnit     $unit,
        array             $overrides = []
    ): InventoryItem {
        static $seq = 0;
        $seq++;

        return InventoryItem::create(array_merge([
            'property_id'  => $property->id,
            'item_code'    => "ITM-{$seq}",
            'name'         => "Item {$seq}",
            'category_id'  => $category->id,
            'unit_id'      => $unit->id,
            'is_active'    => true,
            'average_cost' => '0.0000',
        ], $overrides));
    }

    private function makeStockBalance(
        Property         $property,
        InventoryItem    $item,
        InventoryLocation $location,
        array            $overrides = []
    ): InventoryStockBalance {
        return InventoryStockBalance::create(array_merge([
            'property_id' => $property->id,
            'item_id'     => $item->id,
            'location_id' => $location->id,
            'quantity'    => '0.000',
            'status'      => ItemStatusEnum::OutOfStock->value,
        ], $overrides));
    }

    private function makeReceipt(Property $property, array $overrides = []): InventoryReceipt
    {
        static $seq = 0;
        $seq++;

        return InventoryReceipt::create(array_merge([
            'property_id'    => $property->id,
            'receipt_number' => "RCT-{$seq}",
            'status'         => ReceiptStatusEnum::Draft->value,
        ], $overrides));
    }

    private function makeIssue(Property $property, array $overrides = []): InventoryIssue
    {
        static $seq = 0;
        $seq++;

        return InventoryIssue::create(array_merge([
            'property_id'  => $property->id,
            'issue_number' => "ISS-{$seq}",
            'status'       => IssueStatusEnum::Draft->value,
        ], $overrides));
    }

    private function makeTransfer(
        Property         $property,
        InventoryLocation $from,
        InventoryLocation $to,
        User             $user,
        array            $overrides = []
    ): InventoryTransfer {
        static $seq = 0;
        $seq++;

        return InventoryTransfer::create(array_merge([
            'property_id'      => $property->id,
            'transfer_number'  => "TRN-{$seq}",
            'from_location_id' => $from->id,
            'to_location_id'   => $to->id,
            'status'           => TransferStatusEnum::Draft->value,
            'requested_by'     => $user->id,
        ], $overrides));
    }

    private function makeAdjustment(
        Property         $property,
        InventoryLocation $location,
        array            $overrides = []
    ): InventoryAdjustment {
        static $seq = 0;
        $seq++;

        return InventoryAdjustment::create(array_merge([
            'property_id'       => $property->id,
            'adjustment_number' => "ADJ-{$seq}",
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'status'            => AdjustmentStatusEnum::Draft->value,
            'reason'            => 'Routine count',
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Container resolution
    // ─────────────────────────────────────────────────────────────────────────

    public function test_all_repositories_resolve_from_container(): void
    {
        $this->assertInstanceOf(InventoryCategoryRepository::class,    app(InventoryCategoryRepository::class));
        $this->assertInstanceOf(InventoryUnitRepository::class,        app(InventoryUnitRepository::class));
        $this->assertInstanceOf(InventoryLocationRepository::class,    app(InventoryLocationRepository::class));
        $this->assertInstanceOf(InventoryItemRepository::class,        app(InventoryItemRepository::class));
        $this->assertInstanceOf(InventoryStockBalanceRepository::class, app(InventoryStockBalanceRepository::class));
        $this->assertInstanceOf(InventoryStockCardRepository::class,   app(InventoryStockCardRepository::class));
        $this->assertInstanceOf(InventoryReceiptRepository::class,     app(InventoryReceiptRepository::class));
        $this->assertInstanceOf(InventoryIssueRepository::class,       app(InventoryIssueRepository::class));
        $this->assertInstanceOf(InventoryTransferRepository::class,    app(InventoryTransferRepository::class));
        $this->assertInstanceOf(InventoryAdjustmentRepository::class,  app(InventoryAdjustmentRepository::class));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryCategoryRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_category_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo     = app(InventoryCategoryRepository::class);
        $category = $this->makeCategory($property, ['name' => 'Cleaning Supplies']);

        $found = $repo->find($category->id);

        $this->assertSame($category->id, $found->id);
        $this->assertSame('Cleaning Supplies', $found->name);
    }

    public function test_category_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryCategoryRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_category_repository_update(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo     = app(InventoryCategoryRepository::class);
        $category = $this->makeCategory($property);

        $updated = $repo->update($category->id, ['name' => 'Updated Name']);

        $this->assertSame('Updated Name', $updated->name);
    }

    public function test_category_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo     = app(InventoryCategoryRepository::class);
        $category = $this->makeCategory($property);

        $this->assertTrue($repo->delete($category->id));
        $this->assertSoftDeleted('inventory_categories', ['id' => $category->id]);
    }

    public function test_category_repository_active_returns_only_active(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeCategory($property, ['name' => 'Active Cat',   'is_active' => true]);
        $this->makeCategory($property, ['name' => 'Inactive Cat', 'is_active' => false]);

        $active = app(InventoryCategoryRepository::class)->active();

        $this->assertCount(1, $active);
        $this->assertSame('Active Cat', $active->first()->name);
    }

    public function test_category_repository_paginate_filters_by_name(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeCategory($property, ['name' => 'Food Items']);
        $this->makeCategory($property, ['name' => 'Cleaning']);

        $results = app(InventoryCategoryRepository::class)->paginate(['name' => 'food']);

        $this->assertSame(1, $results->total());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryUnitRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unit_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(InventoryUnitRepository::class);
        $unit = $this->makeUnit($property);

        $found = $repo->find($unit->id);

        $this->assertSame($unit->id, $found->id);
    }

    public function test_unit_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryUnitRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_unit_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo = app(InventoryUnitRepository::class);
        $unit = $this->makeUnit($property);

        $this->assertTrue($repo->delete($unit->id));
        $this->assertSoftDeleted('inventory_units', ['id' => $unit->id]);
    }

    public function test_unit_repository_active_returns_only_active(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeUnit($property, ['name' => 'Active Unit',   'is_active' => true]);
        $this->makeUnit($property, ['name' => 'Inactive Unit', 'is_active' => false]);

        $active = app(InventoryUnitRepository::class)->active();

        $this->assertCount(1, $active);
        $this->assertSame('Active Unit', $active->first()->name);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryLocationRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_location_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo     = app(InventoryLocationRepository::class);
        $location = $this->makeLocation($property, ['name' => 'Main Store']);

        $found = $repo->find($location->id);

        $this->assertSame($location->id, $found->id);
        $this->assertSame('Main Store', $found->name);
    }

    public function test_location_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryLocationRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_location_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo     = app(InventoryLocationRepository::class);
        $location = $this->makeLocation($property);

        $this->assertTrue($repo->delete($location->id));
        $this->assertSoftDeleted('inventory_locations', ['id' => $location->id]);
    }

    public function test_location_repository_active_returns_only_active(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeLocation($property, ['name' => 'Active Loc',   'is_active' => true]);
        $this->makeLocation($property, ['name' => 'Inactive Loc', 'is_active' => false]);

        $active = app(InventoryLocationRepository::class)->active();

        $this->assertCount(1, $active);
    }

    public function test_location_repository_by_type(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeLocation($property, ['location_type' => LocationTypeEnum::MainStore->value]);
        $this->makeLocation($property, ['location_type' => LocationTypeEnum::DepartmentStore->value]);

        $mainStores = app(InventoryLocationRepository::class)->byType(LocationTypeEnum::MainStore);

        $this->assertCount(1, $mainStores);
        $this->assertSame(LocationTypeEnum::MainStore, $mainStores->first()->location_type);
    }

    public function test_location_repository_paginate_filters_by_type(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeLocation($property, ['location_type' => LocationTypeEnum::MainStore->value]);
        $this->makeLocation($property, ['location_type' => LocationTypeEnum::MinibarStore->value]);

        $results = app(InventoryLocationRepository::class)
            ->paginate(['location_type' => LocationTypeEnum::MinibarStore->value]);

        $this->assertSame(1, $results->total());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryItemRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_item_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $repo = app(InventoryItemRepository::class);
        $item = $this->makeItem($property, $cat, $unit, ['name' => 'Hand Soap']);

        $found = $repo->find($item->id);

        $this->assertSame($item->id, $found->id);
        $this->assertSame('Hand Soap', $found->name);
        $this->assertNotNull($found->category);
        $this->assertNotNull($found->unit);
    }

    public function test_item_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryItemRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_item_repository_update(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $repo = app(InventoryItemRepository::class);
        $item = $this->makeItem($property, $cat, $unit);

        $updated = $repo->update($item->id, ['name' => 'Updated Item Name']);

        $this->assertSame('Updated Item Name', $updated->name);
    }

    public function test_item_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat  = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $repo = app(InventoryItemRepository::class);
        $item = $this->makeItem($property, $cat, $unit);

        $this->assertTrue($repo->delete($item->id));
        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);
    }

    public function test_item_repository_paginate_filters_by_category(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat1 = $this->makeCategory($property);
        $cat2 = $this->makeCategory($property);
        $unit = $this->makeUnit($property);
        $this->makeItem($property, $cat1, $unit, ['name' => 'Item A']);
        $this->makeItem($property, $cat2, $unit, ['name' => 'Item B']);

        $results = app(InventoryItemRepository::class)->paginate(['category_id' => $cat1->id]);

        $this->assertSame(1, $results->total());
        $this->assertSame('Item A', $results->items()[0]->name);
    }

    public function test_item_repository_out_of_stock(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $itemA    = $this->makeItem($property, $cat, $unit, ['name' => 'No Stock Item']);
        $itemB    = $this->makeItem($property, $cat, $unit, ['name' => 'Has Stock Item']);

        // Give item B some stock
        $this->makeStockBalance($property, $itemB, $location, [
            'quantity' => '10.000',
            'status'   => ItemStatusEnum::InStock->value,
        ]);

        $outOfStock = app(InventoryItemRepository::class)->outOfStock();

        $ids = $outOfStock->pluck('id')->all();
        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids);
    }

    public function test_item_repository_low_stock(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $itemLow  = $this->makeItem($property, $cat, $unit, ['name' => 'Low Item']);
        $itemOk   = $this->makeItem($property, $cat, $unit, ['name' => 'OK Item']);

        $this->makeStockBalance($property, $itemLow, $location, [
            'quantity' => '2.000',
            'status'   => ItemStatusEnum::LowStock->value,
        ]);
        $this->makeStockBalance($property, $itemOk, $location, [
            'quantity' => '50.000',
            'status'   => ItemStatusEnum::InStock->value,
        ]);

        $lowStock = app(InventoryItemRepository::class)->lowStock();

        $ids = $lowStock->pluck('id')->all();
        $this->assertContains($itemLow->id, $ids);
        $this->assertNotContains($itemOk->id, $ids);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryStockBalanceRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stock_balance_repository_find_or_create_creates_when_missing(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $repo     = app(InventoryStockBalanceRepository::class);

        $balance = $repo->findOrCreate($item->id, $location->id, $property->id);

        $this->assertInstanceOf(InventoryStockBalance::class, $balance);
        $this->assertSame($item->id, $balance->item_id);
        $this->assertSame(ItemStatusEnum::OutOfStock, $balance->status);
        $this->assertDatabaseHas('inventory_stock_balances', ['item_id' => $item->id]);
    }

    public function test_stock_balance_repository_find_or_create_returns_existing(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $existing = $this->makeStockBalance($property, $item, $location, ['quantity' => '5.000']);
        $repo     = app(InventoryStockBalanceRepository::class);

        $found = $repo->findOrCreate($item->id, $location->id, $property->id);

        $this->assertSame($existing->id, $found->id);
    }

    public function test_stock_balance_repository_for_item(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat   = $this->makeCategory($property);
        $unit  = $this->makeUnit($property);
        $loc1  = $this->makeLocation($property);
        $loc2  = $this->makeLocation($property);
        $item  = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc1);
        $this->makeStockBalance($property, $item, $loc2);

        $balances = app(InventoryStockBalanceRepository::class)->forItem($item->id);

        $this->assertCount(2, $balances);
    }

    public function test_stock_balance_repository_for_location(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item1    = $this->makeItem($property, $cat, $unit);
        $item2    = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item1, $location);
        $this->makeStockBalance($property, $item2, $location);

        $balances = app(InventoryStockBalanceRepository::class)->forLocation($location->id);

        $this->assertCount(2, $balances);
    }

    public function test_stock_balance_repository_total_quantity_for_item(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat   = $this->makeCategory($property);
        $unit  = $this->makeUnit($property);
        $loc1  = $this->makeLocation($property);
        $loc2  = $this->makeLocation($property);
        $item  = $this->makeItem($property, $cat, $unit);

        $this->makeStockBalance($property, $item, $loc1, ['quantity' => '10.000']);
        $this->makeStockBalance($property, $item, $loc2, ['quantity' => '5.500']);

        $total = app(InventoryStockBalanceRepository::class)->totalQuantityForItem($item->id);

        $this->assertEqualsWithDelta(15.5, (float) $total, 0.001);
    }

    public function test_stock_balance_repository_update_balance(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $balance  = $this->makeStockBalance($property, $item, $location);
        $repo     = app(InventoryStockBalanceRepository::class);

        $repo->updateBalance($balance->id, '25.000', ItemStatusEnum::InStock);

        $this->assertDatabaseHas('inventory_stock_balances', [
            'id'     => $balance->id,
            'status' => ItemStatusEnum::InStock->value,
        ]);
    }

    public function test_stock_balance_repository_lock_for_update_returns_model(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $this->makeStockBalance($property, $item, $location);
        $repo = app(InventoryStockBalanceRepository::class);

        DB::beginTransaction();
        $locked = $repo->lockForUpdate($item->id, $location->id);
        DB::rollBack();

        $this->assertInstanceOf(InventoryStockBalance::class, $locked);
        $this->assertSame($item->id, $locked->item_id);
    }

    public function test_stock_balance_repository_lock_for_update_returns_null_when_missing(): void
    {
        ['property' => $property] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $repo     = app(InventoryStockBalanceRepository::class);

        DB::beginTransaction();
        $locked = $repo->lockForUpdate($item->id, $location->id);
        DB::rollBack();

        $this->assertNull($locked);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryStockCardRepository — append-only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stock_card_repository_has_no_update_method(): void
    {
        $repo = app(InventoryStockCardRepository::class);
        $this->assertFalse(method_exists($repo, 'update'));
    }

    public function test_stock_card_repository_has_no_delete_method(): void
    {
        $repo = app(InventoryStockCardRepository::class);
        $this->assertFalse(method_exists($repo, 'delete'));
    }

    public function test_stock_card_repository_create_persists_record(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $repo     = app(InventoryStockCardRepository::class);

        $card = $repo->create([
            'property_id'     => $property->id,
            'item_id'         => $item->id,
            'location_id'     => $location->id,
            'movement_type'   => TransactionTypeEnum::OpeningBalance->value,
            'quantity_before' => '0.000',
            'quantity_change' => '10.000',
            'quantity_after'  => '10.000',
            'posted_by'       => $admin->id,
            'posted_at'       => now(),
        ]);

        $this->assertDatabaseHas('inventory_stock_cards', ['id' => $card->id]);
        $this->assertSame($item->id, $card->item_id);
    }

    public function test_stock_card_repository_for_item_returns_cards_in_desc_order(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $repo     = app(InventoryStockCardRepository::class);

        $repo->create([
            'property_id' => $property->id, 'item_id' => $item->id, 'location_id' => $location->id,
            'movement_type' => TransactionTypeEnum::OpeningBalance->value,
            'quantity_before' => '0.000', 'quantity_change' => '10.000', 'quantity_after' => '10.000',
            'posted_by' => $admin->id, 'posted_at' => now()->subMinutes(5),
        ]);
        $repo->create([
            'property_id' => $property->id, 'item_id' => $item->id, 'location_id' => $location->id,
            'movement_type' => TransactionTypeEnum::Issue->value,
            'quantity_before' => '10.000', 'quantity_change' => '-3.000', 'quantity_after' => '7.000',
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);

        $cards = $repo->forItem($item->id);

        $this->assertCount(2, $cards);
        // Most recent first
        $this->assertSame(TransactionTypeEnum::Issue, $cards->first()->movement_type);
    }

    public function test_stock_card_repository_paginate_filters_by_movement_type(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $cat      = $this->makeCategory($property);
        $unit     = $this->makeUnit($property);
        $location = $this->makeLocation($property);
        $item     = $this->makeItem($property, $cat, $unit);
        $repo     = app(InventoryStockCardRepository::class);

        $repo->create([
            'property_id' => $property->id, 'item_id' => $item->id, 'location_id' => $location->id,
            'movement_type' => TransactionTypeEnum::OpeningBalance->value,
            'quantity_before' => '0.000', 'quantity_change' => '10.000', 'quantity_after' => '10.000',
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);
        $repo->create([
            'property_id' => $property->id, 'item_id' => $item->id, 'location_id' => $location->id,
            'movement_type' => TransactionTypeEnum::Issue->value,
            'quantity_before' => '10.000', 'quantity_change' => '-2.000', 'quantity_after' => '8.000',
            'posted_by' => $admin->id, 'posted_at' => now(),
        ]);

        $results = $repo->paginate(['movement_type' => TransactionTypeEnum::Issue->value]);

        $this->assertSame(1, $results->total());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryReceiptRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_receipt_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo    = app(InventoryReceiptRepository::class);
        $receipt = $this->makeReceipt($property, ['supplier_name' => 'ACME Corp']);

        $found = $repo->find($receipt->id);

        $this->assertSame($receipt->id, $found->id);
        $this->assertSame('ACME Corp', $found->supplier_name);
    }

    public function test_receipt_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryReceiptRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_receipt_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo    = app(InventoryReceiptRepository::class);
        $receipt = $this->makeReceipt($property);

        $this->assertTrue($repo->delete($receipt->id));
        $this->assertSoftDeleted('inventory_receipts', ['id' => $receipt->id]);
    }

    public function test_receipt_repository_by_status_draft(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeReceipt($property, ['status' => ReceiptStatusEnum::Draft->value]);
        $this->makeReceipt($property, ['status' => ReceiptStatusEnum::Posted->value]);

        $drafts = app(InventoryReceiptRepository::class)->byStatus(ReceiptStatusEnum::Draft);

        $this->assertCount(1, $drafts);
        $this->assertSame(ReceiptStatusEnum::Draft, $drafts->first()->status);
    }

    public function test_receipt_repository_paginate_filters_by_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeReceipt($property, ['status' => ReceiptStatusEnum::Draft->value]);
        $this->makeReceipt($property, ['status' => ReceiptStatusEnum::Cancelled->value]);

        $results = app(InventoryReceiptRepository::class)
            ->paginate(['status' => ReceiptStatusEnum::Cancelled->value]);

        $this->assertSame(1, $results->total());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryIssueRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_issue_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo  = app(InventoryIssueRepository::class);
        $issue = $this->makeIssue($property);

        $found = $repo->find($issue->id);

        $this->assertSame($issue->id, $found->id);
    }

    public function test_issue_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryIssueRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_issue_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $repo  = app(InventoryIssueRepository::class);
        $issue = $this->makeIssue($property);

        $this->assertTrue($repo->delete($issue->id));
        $this->assertSoftDeleted('inventory_issues', ['id' => $issue->id]);
    }

    public function test_issue_repository_by_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeIssue($property, ['status' => IssueStatusEnum::Draft->value]);
        $this->makeIssue($property, ['status' => IssueStatusEnum::Posted->value]);

        $posted = app(InventoryIssueRepository::class)->byStatus(IssueStatusEnum::Posted);

        $this->assertCount(1, $posted);
        $this->assertSame(IssueStatusEnum::Posted, $posted->first()->status);
    }

    public function test_issue_repository_paginate_filters_by_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $this->makeIssue($property, ['status' => IssueStatusEnum::Draft->value]);
        $this->makeIssue($property, ['status' => IssueStatusEnum::Posted->value]);

        $results = app(InventoryIssueRepository::class)
            ->paginate(['status' => IssueStatusEnum::Draft->value]);

        $this->assertSame(1, $results->total());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryTransferRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_transfer_repository_create_and_find(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $from     = $this->makeLocation($property);
        $to       = $this->makeLocation($property);
        $repo     = app(InventoryTransferRepository::class);
        $transfer = $this->makeTransfer($property, $from, $to, $admin);

        $found = $repo->find($transfer->id);

        $this->assertSame($transfer->id, $found->id);
        $this->assertNotNull($found->fromLocation);
        $this->assertNotNull($found->toLocation);
    }

    public function test_transfer_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryTransferRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_transfer_repository_delete_soft_deletes(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $from     = $this->makeLocation($property);
        $to       = $this->makeLocation($property);
        $repo     = app(InventoryTransferRepository::class);
        $transfer = $this->makeTransfer($property, $from, $to, $admin);

        $this->assertTrue($repo->delete($transfer->id));
        $this->assertSoftDeleted('inventory_transfers', ['id' => $transfer->id]);
    }

    public function test_transfer_repository_pending_returns_submitted_only(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $from = $this->makeLocation($property);
        $to   = $this->makeLocation($property);
        $this->makeTransfer($property, $from, $to, $admin, ['status' => TransferStatusEnum::Draft->value]);
        $this->makeTransfer($property, $from, $to, $admin, ['status' => TransferStatusEnum::Submitted->value]);

        $pending = app(InventoryTransferRepository::class)->pending();

        $this->assertCount(1, $pending);
        $this->assertSame(TransferStatusEnum::Submitted, $pending->first()->status);
    }

    public function test_transfer_repository_by_status(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootProperty();
        $from = $this->makeLocation($property);
        $to   = $this->makeLocation($property);
        $this->makeTransfer($property, $from, $to, $admin, ['status' => TransferStatusEnum::Completed->value]);
        $this->makeTransfer($property, $from, $to, $admin, ['status' => TransferStatusEnum::Draft->value]);

        $completed = app(InventoryTransferRepository::class)->byStatus(TransferStatusEnum::Completed);

        $this->assertCount(1, $completed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // InventoryAdjustmentRepository
    // ─────────────────────────────────────────────────────────────────────────

    public function test_adjustment_repository_create_and_find(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location   = $this->makeLocation($property);
        $repo       = app(InventoryAdjustmentRepository::class);
        $adjustment = $this->makeAdjustment($property, $location);

        $found = $repo->find($adjustment->id);

        $this->assertSame($adjustment->id, $found->id);
        $this->assertNotNull($found->location);
    }

    public function test_adjustment_repository_find_throws_not_found(): void
    {
        $this->bootProperty();
        $this->expectException(NotFoundException::class);
        app(InventoryAdjustmentRepository::class)->find('01JXXXXXXXXXXXXXXXXXXXXXXXXX');
    }

    public function test_adjustment_repository_delete_soft_deletes(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location   = $this->makeLocation($property);
        $repo       = app(InventoryAdjustmentRepository::class);
        $adjustment = $this->makeAdjustment($property, $location);

        $this->assertTrue($repo->delete($adjustment->id));
        $this->assertSoftDeleted('inventory_adjustments', ['id' => $adjustment->id]);
    }

    public function test_adjustment_repository_by_status(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeLocation($property);
        $this->makeAdjustment($property, $location, ['status' => AdjustmentStatusEnum::Draft->value]);
        $this->makeAdjustment($property, $location, ['status' => AdjustmentStatusEnum::Submitted->value]);

        $submitted = app(InventoryAdjustmentRepository::class)->byStatus(AdjustmentStatusEnum::Submitted);

        $this->assertCount(1, $submitted);
        $this->assertSame(AdjustmentStatusEnum::Submitted, $submitted->first()->status);
    }

    public function test_adjustment_repository_paginate_filters_by_type(): void
    {
        ['property' => $property] = $this->bootProperty();
        $location = $this->makeLocation($property);
        $this->makeAdjustment($property, $location, ['adjustment_type' => AdjustmentTypeEnum::StockTake->value]);
        $this->makeAdjustment($property, $location, ['adjustment_type' => AdjustmentTypeEnum::Damaged->value]);

        $results = app(InventoryAdjustmentRepository::class)
            ->paginate(['adjustment_type' => AdjustmentTypeEnum::Damaged->value]);

        $this->assertSame(1, $results->total());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Property isolation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_category_repository_respects_property_isolation(): void
    {
        ['property' => $propertyA] = $this->bootProperty();
        $this->makeCategory($propertyA);

        $companyB  = $this->createCompany();
        $propertyB = $this->createProperty($companyB, ['code' => 'PIB1']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertSame(0, app(InventoryCategoryRepository::class)->paginate()->total());
    }

    public function test_item_repository_respects_property_isolation(): void
    {
        ['property' => $propertyA] = $this->bootProperty();
        $cat  = $this->makeCategory($propertyA);
        $unit = $this->makeUnit($propertyA);
        $this->makeItem($propertyA, $cat, $unit);

        $companyB  = $this->createCompany();
        $propertyB = $this->createProperty($companyB, ['code' => 'PIB2']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertSame(0, app(InventoryItemRepository::class)->paginate()->total());
    }

    public function test_receipt_repository_respects_property_isolation(): void
    {
        ['property' => $propertyA] = $this->bootProperty();
        $this->makeReceipt($propertyA);

        $companyB  = $this->createCompany();
        $propertyB = $this->createProperty($companyB, ['code' => 'PIB3']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertSame(0, app(InventoryReceiptRepository::class)->paginate()->total());
    }

    public function test_transfer_repository_respects_property_isolation(): void
    {
        ['property' => $propertyA, 'admin' => $adminA] = $this->bootProperty();
        $from = $this->makeLocation($propertyA);
        $to   = $this->makeLocation($propertyA);
        $this->makeTransfer($propertyA, $from, $to, $adminA);

        $companyB  = $this->createCompany();
        $propertyB = $this->createProperty($companyB, ['code' => 'PIB4']);
        $adminB    = $this->createPropertyAdmin($propertyB);
        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertSame(0, app(InventoryTransferRepository::class)->paginate()->total());
    }
}
