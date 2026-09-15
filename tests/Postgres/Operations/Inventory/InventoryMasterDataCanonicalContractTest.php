<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Http\Resources\InventoryCategoryResource;
use Modules\Operations\Inventory\Http\Resources\InventoryItemResource;
use Modules\Operations\Inventory\Http\Resources\InventoryLocationResource;
use Modules\Operations\Inventory\Http\Resources\InventoryUnitResource;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class InventoryMasterDataCanonicalContractTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Property $property;

    private Property $otherProperty;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'name' => 'Inventory Contract Company',
            'slug' => 'inventory-contract-company',
            'is_active' => true,
        ]);

        $this->property = $this->property($company, 'Inventory Contract Property', 'ICP');
        $this->otherProperty = $this->property($company, 'Inventory Other Property', 'IOP');
        $this->user = User::create([
            'name' => 'Inventory Contract User',
            'email' => 'inventory-contract@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->user->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        Gate::before(fn (User $user, string $ability) => $user->is($this->user) ? true : null);
    }

    public function test_category_is_created_through_http_with_only_canonical_fields(): void
    {
        $response = $this->postAsCurrentProperty('operations.inventory.categories.store', [
            'name' => 'Food',
            'description' => 'Food inventory',
            'parent_id' => null,
        ]);

        $category = InventoryCategory::where('name', 'Food')->firstOrFail();

        $response->assertRedirect(route('operations.inventory.categories.show', $category->id));
        $this->assertSame($this->property->id, $category->property_id);
        $this->assertSame('Food inventory', $category->description);
        $this->assertSame($this->user->id, $category->created_by);
        $this->assertSame($this->user->id, $category->updated_by);
    }

    public function test_unit_is_created_through_http_with_only_canonical_fields(): void
    {
        $response = $this->postAsCurrentProperty('operations.inventory.units.store', [
            'code' => 'EA',
            'name' => 'Each',
        ]);

        $unit = InventoryUnit::where('code', 'EA')->firstOrFail();

        $response->assertRedirect(route('operations.inventory.units.show', $unit->id));
        $this->assertSame($this->property->id, $unit->property_id);
    }

    public function test_location_is_created_through_http_with_only_canonical_fields(): void
    {
        $response = $this->postAsCurrentProperty('operations.inventory.locations.store', [
            'name' => 'Main Store',
            'type' => 'main_store',
            'parent_id' => null,
        ]);

        $location = InventoryLocation::where('name', 'Main Store')->firstOrFail();

        $response->assertRedirect(route('operations.inventory.locations.show', $location->id));
        $this->assertSame('main_store', $location->type);
    }

    public function test_item_is_created_through_http_with_only_canonical_fields(): void
    {
        $category = $this->category('Consumables');

        $response = $this->postAsCurrentProperty('operations.inventory.items.store', [
            'sku' => 'SOAP-001',
            'name' => 'Guest Soap',
            'category_id' => $category->id,
            'inventory_type' => 'consumable',
            'criticality' => 'medium',
            'is_batch_tracked' => true,
            'is_expiry_tracked' => false,
            'is_active' => true,
            'reorder_point' => '25.5000',
        ]);

        $item = InventoryItem::where('sku', 'SOAP-001')->firstOrFail();

        $response->assertRedirect(route('operations.inventory.items.show', $item->id));
        $this->assertSame('consumable', $item->inventory_type);
        $this->assertSame('0.00', $item->weighted_average_cost);
    }

    public function test_validation_and_repository_queries_use_only_canonical_columns(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $category = $this->category('Query Proof');
        $this->postAsCurrentProperty('operations.inventory.units.store', ['code' => 'KG', 'name' => 'Kilogram']);
        $this->postAsCurrentProperty('operations.inventory.locations.store', ['name' => 'Query Store', 'type' => 'main_store']);
        $this->postAsCurrentProperty('operations.inventory.items.store', [
            'sku' => 'QUERY-001', 'name' => 'Query Item', 'category_id' => $category->id,
            'inventory_type' => 'consumable', 'criticality' => 'low',
        ]);
        app(InventoryMasterDataService::class)->paginateCategories(['name' => 'Query']);
        app(InventoryMasterDataService::class)->paginateUnits(['name' => 'KG']);
        app(InventoryMasterDataService::class)->paginateLocations(['type' => 'main_store']);
        app(InventoryMasterDataService::class)->paginateItems(['name' => 'QUERY']);

        $sql = implode("\n", $queries);
        foreach (['item_code', 'category_code', 'unit_code', 'location_code', 'location_type'] as $removedColumn) {
            $this->assertStringNotContainsString($removedColumn, $sql);
        }
        $this->assertStringNotContainsString('inventory_items"."unit_id', $sql);
    }

    public function test_property_id_is_server_controlled(): void
    {
        $this->postAsCurrentProperty('operations.inventory.categories.store', [
            'name' => 'Injected Property',
            'property_id' => $this->otherProperty->id,
        ])->assertSessionHasErrors('property_id');

        $this->assertDatabaseMissing('inventory_categories', ['name' => 'Injected Property']);
    }

    public function test_category_parent_must_be_live_and_in_current_property(): void
    {
        $otherParent = $this->inProperty($this->otherProperty, fn () => $this->category('Other Category'));

        $this->postAsCurrentProperty('operations.inventory.categories.store', [
            'name' => 'Invalid Child',
            'parent_id' => $otherParent->id,
        ])->assertSessionHasErrors('parent_id');

        $deletedParent = $this->category('Deleted Parent');
        $deletedParent->delete();
        $this->postAsCurrentProperty('operations.inventory.categories.store', [
            'name' => 'Deleted Child',
            'parent_id' => $deletedParent->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_location_parent_must_be_live_and_in_current_property(): void
    {
        $otherParent = $this->inProperty($this->otherProperty, fn () => $this->location('Other Store'));

        $this->postAsCurrentProperty('operations.inventory.locations.store', [
            'name' => 'Invalid Store Child',
            'type' => 'department_store',
            'parent_id' => $otherParent->id,
        ])->assertSessionHasErrors('parent_id');

        $deletedParent = $this->location('Deleted Store');
        $deletedParent->delete();
        $this->postAsCurrentProperty('operations.inventory.locations.store', [
            'name' => 'Deleted Store Child',
            'type' => 'department_store',
            'parent_id' => $deletedParent->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_item_category_must_be_live_and_in_current_property(): void
    {
        $otherCategory = $this->inProperty($this->otherProperty, fn () => $this->category('Other Item Category'));

        $this->postAsCurrentProperty('operations.inventory.items.store', [
            'sku' => 'INVALID-CATEGORY', 'name' => 'Invalid Category Item',
            'category_id' => $otherCategory->id, 'inventory_type' => 'consumable',
        ])->assertSessionHasErrors('category_id');

        $deletedCategory = $this->category('Deleted Item Category');
        $deletedCategory->delete();
        $this->postAsCurrentProperty('operations.inventory.items.store', [
            'sku' => 'DELETED-CATEGORY', 'name' => 'Deleted Category Item',
            'category_id' => $deletedCategory->id, 'inventory_type' => 'consumable',
        ])->assertSessionHasErrors('category_id');
    }

    public function test_weighted_average_cost_cannot_be_client_written(): void
    {
        $category = $this->category('Cost Protected');

        $this->postAsCurrentProperty('operations.inventory.items.store', [
            'sku' => 'COST-001', 'name' => 'Protected Cost', 'category_id' => $category->id,
            'inventory_type' => 'consumable', 'weighted_average_cost' => '999.99',
        ])->assertSessionHasErrors('weighted_average_cost');

        $this->assertDatabaseMissing('inventory_items', ['sku' => 'COST-001']);
    }

    public function test_soft_deleted_natural_keys_can_be_reused(): void
    {
        $category = $this->category('Reusable Category');
        $unit = InventoryUnit::create(['property_id' => $this->property->id, 'code' => 'REUSE', 'name' => 'Reusable Unit']);
        $location = $this->location('Reusable Store');
        $item = $this->item($category, 'REUSE-ITEM');
        $category->delete();
        $unit->delete();
        $location->delete();
        $item->delete();

        $this->postAsCurrentProperty('operations.inventory.categories.store', ['name' => 'Reusable Category'])->assertSessionHasNoErrors();
        $newCategory = InventoryCategory::where('name', 'Reusable Category')->firstOrFail();
        $this->postAsCurrentProperty('operations.inventory.units.store', ['code' => 'REUSE', 'name' => 'Replacement Unit'])->assertSessionHasNoErrors();
        $this->postAsCurrentProperty('operations.inventory.locations.store', ['name' => 'Reusable Store', 'type' => 'main_store'])->assertSessionHasNoErrors();
        $this->postAsCurrentProperty('operations.inventory.items.store', [
            'sku' => 'REUSE-ITEM', 'name' => 'Replacement Item', 'category_id' => $newCategory->id,
            'inventory_type' => 'consumable',
        ])->assertSessionHasNoErrors();
    }

    public function test_create_update_read_round_trip_uses_one_canonical_contract(): void
    {
        $category = $this->category('Round Trip');
        $unit = InventoryUnit::create(['property_id' => $this->property->id, 'code' => 'RT', 'name' => 'Round Trip Unit']);
        $location = $this->location('Round Trip Store');
        $item = $this->item($category, 'ROUND-001');

        $this->putAsCurrentProperty('operations.inventory.categories.update', ['category' => $category->id], ['name' => 'Round Trip Category']);
        $this->putAsCurrentProperty('operations.inventory.units.update', ['unit' => $unit->id], ['code' => 'RT2', 'name' => 'Round Trip Unit 2']);
        $this->putAsCurrentProperty('operations.inventory.locations.update', ['location' => $location->id], ['name' => 'Round Trip Store 2', 'type' => 'department_store']);
        $this->putAsCurrentProperty('operations.inventory.items.update', ['item' => $item->id], [
            'sku' => 'ROUND-002', 'name' => 'Round Trip Item 2', 'category_id' => $category->id,
            'inventory_type' => 'spare_part', 'criticality' => 'high', 'reorder_point' => 4,
        ]);

        $resources = [
            (new InventoryCategoryResource($category->refresh()))->resolve(),
            (new InventoryUnitResource($unit->refresh()))->resolve(),
            (new InventoryLocationResource($location->refresh()))->resolve(),
            (new InventoryItemResource($item->refresh()->load('category')))->resolve(),
        ];
        $removedKeys = ['item_code', 'category_code', 'unit_code', 'location_code', 'location_type', 'unit_id'];
        foreach ($resources as $resource) {
            foreach ($removedKeys as $removedKey) {
                $this->assertArrayNotHasKey($removedKey, $resource);
            }
        }
        $this->assertSame('ROUND-002', $resources[3]['sku']);
        $this->assertSame('spare_part', $resources[3]['inventory_type']);
        $this->assertArrayNotHasKey('unit', $resources[3]);
        $this->assertSame($this->user->id, $item->updated_by);
    }

    private function property(Company $company, string $name, string $code): Property
    {
        return Property::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => $code,
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function category(string $name): InventoryCategory
    {
        return InventoryCategory::create(['property_id' => app(CurrentPropertyService::class)->resolveOrFail(), 'name' => $name]);
    }

    private function location(string $name): InventoryLocation
    {
        return InventoryLocation::create(['property_id' => app(CurrentPropertyService::class)->resolveOrFail(), 'name' => $name, 'type' => 'main_store']);
    }

    private function item(InventoryCategory $category, string $sku): InventoryItem
    {
        return InventoryItem::create([
            'property_id' => app(CurrentPropertyService::class)->resolveOrFail(),
            'sku' => $sku,
            'name' => $sku,
            'category_id' => $category->id,
            'inventory_type' => 'consumable',
            'criticality' => 'low',
        ]);
    }

    private function postAsCurrentProperty(string $route, array $data)
    {
        return $this->withSession(['current_property_id' => $this->property->id])
            ->actingAs($this->user)
            ->post(route($route), $data);
    }

    private function putAsCurrentProperty(string $route, array $parameters, array $data)
    {
        return $this->withSession(['current_property_id' => $this->property->id])
            ->actingAs($this->user)
            ->put(route($route, $parameters), $data)
            ->assertSessionHasNoErrors();
    }

    private function inProperty(Property $property, callable $callback)
    {
        $currentProperty = app(CurrentPropertyService::class);
        $currentProperty->setPropertyId($property->id);

        try {
            return $callback();
        } finally {
            $currentProperty->setPropertyId($this->property->id);
        }
    }
}
