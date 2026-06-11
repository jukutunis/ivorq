<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Enums\AdjustmentStatusEnum;
use Modules\Operations\Inventory\Enums\AdjustmentTypeEnum;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Enums\LocationTypeEnum;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesInventoryData;
use Tests\TestCase;

class InventoryControllerTest extends TestCase
{
    use RefreshDatabase, CreatesInventoryData;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function bootAdmin(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);
        $this->seedInventoryPermissions();

        return compact('company', 'property', 'admin');
    }

    private function bootStaff(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);
        $this->seedInventoryPermissions();

        return compact('company', 'property', 'staff');
    }

    // ─── Route name assertions ─────────────────────────────────────────────────

    public function test_inventory_route_names_are_registered(): void
    {
        $routeNames = [
            'operations.inventory.dashboard',
            'operations.inventory.categories.index',
            'operations.inventory.categories.store',
            'operations.inventory.categories.show',
            'operations.inventory.categories.update',
            'operations.inventory.categories.destroy',
            'operations.inventory.units.index',
            'operations.inventory.units.store',
            'operations.inventory.units.show',
            'operations.inventory.locations.index',
            'operations.inventory.locations.store',
            'operations.inventory.locations.show',
            'operations.inventory.items.index',
            'operations.inventory.items.store',
            'operations.inventory.items.show',
            'operations.inventory.receipts.index',
            'operations.inventory.receipts.store',
            'operations.inventory.receipts.show',
            'operations.inventory.receipts.post',
            'operations.inventory.receipts.cancel',
            'operations.inventory.issues.index',
            'operations.inventory.issues.store',
            'operations.inventory.issues.show',
            'operations.inventory.issues.post',
            'operations.inventory.issues.cancel',
            'operations.inventory.transfers.index',
            'operations.inventory.transfers.store',
            'operations.inventory.transfers.show',
            'operations.inventory.transfers.complete',
            'operations.inventory.transfers.cancel',
            'operations.inventory.adjustments.index',
            'operations.inventory.adjustments.store',
            'operations.inventory.adjustments.show',
            'operations.inventory.adjustments.submit',
            'operations.inventory.adjustments.approve',
            'operations.inventory.adjustments.reject',
            'operations.inventory.adjustments.cancel',
            'operations.inventory.stock-cards.index',
            'operations.inventory.stock-cards.show',
        ];

        foreach ($routeNames as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name),
                "Route name '{$name}' is not registered."
            );
        }
    }

    // ─── Dashboard ─────────────────────────────────────────────────────────────

    public function test_admin_can_view_inventory_dashboard(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory')->assertOk();
    }

    public function test_unauthenticated_user_cannot_view_dashboard(): void
    {
        $this->get('/operations/inventory')->assertRedirect('/login');
    }

    public function test_staff_cannot_view_inventory_dashboard(): void
    {
        $this->bootStaff();

        $this->get('/operations/inventory')->assertForbidden();
    }

    // ─── Categories ────────────────────────────────────────────────────────────

    public function test_admin_can_view_categories_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/categories')->assertOk();
    }

    public function test_staff_cannot_view_categories_index(): void
    {
        $this->bootStaff();

        $this->get('/operations/inventory/categories')->assertForbidden();
    }

    public function test_admin_can_create_category(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $this->post('/operations/inventory/categories', [
            'category_code' => 'FOOD',
            'name'          => 'Food & Beverage',
            'is_active'     => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_categories', [
            'property_id'   => $property->id,
            'category_code' => 'FOOD',
        ]);
    }

    public function test_staff_cannot_create_category(): void
    {
        $this->bootStaff();

        $this->post('/operations/inventory/categories', [
            'category_code' => 'UNAUTH',
            'name'          => 'Unauthorized',
        ])->assertForbidden();

        $this->assertDatabaseMissing('inventory_categories', ['category_code' => 'UNAUTH']);
    }

    public function test_admin_can_view_own_category(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);

        $this->get("/operations/inventory/categories/{$category->id}")->assertOk();
    }

    public function test_admin_can_update_category(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);

        $this->put("/operations/inventory/categories/{$category->id}", [
            'name' => 'Updated Category',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_categories', [
            'id'   => $category->id,
            'name' => 'Updated Category',
        ]);
    }

    public function test_admin_can_delete_category(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);

        $this->delete("/operations/inventory/categories/{$category->id}")->assertRedirect();

        $this->assertSoftDeleted('inventory_categories', ['id' => $category->id]);
    }

    public function test_cross_property_category_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'IC-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $category = $this->makeInventoryCategory($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/categories/{$category->id}")->assertNotFound();
    }

    // ─── Units ─────────────────────────────────────────────────────────────────

    public function test_admin_can_view_units_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/units')->assertOk();
    }

    public function test_admin_can_create_unit(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $this->post('/operations/inventory/units', [
            'unit_code'    => 'KG',
            'name'         => 'Kilogram',
            'abbreviation' => 'kg',
            'is_active'    => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_units', [
            'property_id' => $property->id,
            'unit_code'   => 'KG',
        ]);
    }

    public function test_staff_cannot_create_unit(): void
    {
        $this->bootStaff();

        $this->post('/operations/inventory/units', [
            'unit_code'    => 'UNAUTH',
            'name'         => 'Unauthorized',
            'abbreviation' => 'u',
        ])->assertForbidden();
    }

    public function test_cross_property_unit_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'IU-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $unit = $this->makeInventoryUnit($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/units/{$unit->id}")->assertNotFound();
    }

    // ─── Locations ─────────────────────────────────────────────────────────────

    public function test_admin_can_view_locations_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/locations')->assertOk();
    }

    public function test_admin_can_create_location(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $this->post('/operations/inventory/locations', [
            'location_code' => 'MAIN-STR',
            'name'          => 'Main Store',
            'location_type' => LocationTypeEnum::MainStore->value,
            'is_active'     => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_locations', [
            'property_id'   => $property->id,
            'location_code' => 'MAIN-STR',
        ]);
    }

    public function test_staff_cannot_create_location(): void
    {
        $this->bootStaff();

        $this->post('/operations/inventory/locations', [
            'location_code' => 'UNAUTH',
            'name'          => 'Unauthorized',
            'location_type' => LocationTypeEnum::MainStore->value,
        ])->assertForbidden();
    }

    public function test_cross_property_location_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'IL-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $location = $this->makeInventoryLocation($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/locations/{$location->id}")->assertNotFound();
    }

    // ─── Items ─────────────────────────────────────────────────────────────────

    public function test_admin_can_view_items_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/items')->assertOk();
    }

    public function test_admin_can_create_item(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);

        $this->post('/operations/inventory/items', [
            'item_code'   => 'SOAP-001',
            'name'        => 'Bath Soap',
            'category_id' => $category->id,
            'unit_id'     => $unit->id,
            'is_active'   => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_items', [
            'property_id' => $property->id,
            'item_code'   => 'SOAP-001',
        ]);
    }

    public function test_staff_cannot_create_item(): void
    {
        ['property' => $property] = $this->bootStaff();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);

        $this->post('/operations/inventory/items', [
            'item_code'   => 'UNAUTH',
            'name'        => 'Unauthorized',
            'category_id' => $category->id,
            'unit_id'     => $unit->id,
        ])->assertForbidden();
    }

    public function test_cross_property_item_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'II-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $category = $this->makeInventoryCategory($propertyA);
        $unit     = $this->makeInventoryUnit($propertyA);
        $item     = $this->makeInventoryItem($propertyA, $category, $unit);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/items/{$item->id}")->assertNotFound();
    }

    // ─── Receipts ──────────────────────────────────────────────────────────────

    public function test_admin_can_view_receipts_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/receipts')->assertOk();
    }

    public function test_admin_can_create_receipt(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $this->post('/operations/inventory/receipts', [
            'receipt_number' => 'RCV-CT-001',
            'supplier_name'  => 'Test Supplier',
            'lines' => [[
                'item_id'     => $item->id,
                'location_id' => $location->id,
                'quantity'    => 10,
                'unit_cost'   => 5.50,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_receipts', [
            'property_id'    => $property->id,
            'receipt_number' => 'RCV-CT-001',
            'status'         => ReceiptStatusEnum::Draft->value,
        ]);
    }

    public function test_staff_cannot_create_receipt(): void
    {
        $this->bootStaff();

        $this->post('/operations/inventory/receipts', [
            'receipt_number' => 'UNAUTH',
        ])->assertForbidden();
    }

    public function test_admin_can_view_own_receipt(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $receipt = $this->makeInventoryReceipt($property);

        $this->get("/operations/inventory/receipts/{$receipt->id}")->assertOk();
    }

    public function test_admin_can_cancel_receipt(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $receipt = $this->makeInventoryReceipt($property);

        $this->post("/operations/inventory/receipts/{$receipt->id}/cancel")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Receipt cancelled.']);

        $this->assertDatabaseHas('inventory_receipts', [
            'id'     => $receipt->id,
            'status' => ReceiptStatusEnum::Cancelled->value,
        ]);
    }

    public function test_cross_property_receipt_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'IR-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $receipt = $this->makeInventoryReceipt($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/receipts/{$receipt->id}")->assertNotFound();
    }

    // ─── Issues ────────────────────────────────────────────────────────────────

    public function test_admin_can_view_issues_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/issues')->assertOk();
    }

    public function test_admin_can_create_issue(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $this->post('/operations/inventory/issues', [
            'issue_number' => 'ISS-CT-001',
            'lines' => [[
                'item_id'     => $item->id,
                'location_id' => $location->id,
                'quantity'    => 3,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_issues', [
            'property_id'  => $property->id,
            'issue_number' => 'ISS-CT-001',
            'status'       => IssueStatusEnum::Draft->value,
        ]);
    }

    public function test_staff_cannot_create_issue(): void
    {
        $this->bootStaff();

        $this->post('/operations/inventory/issues', [
            'issue_number' => 'UNAUTH',
        ])->assertForbidden();
    }

    public function test_admin_can_cancel_issue(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $issue = $this->makeInventoryIssue($property);

        $this->post("/operations/inventory/issues/{$issue->id}/cancel")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Issue cancelled.']);

        $this->assertDatabaseHas('inventory_issues', [
            'id'     => $issue->id,
            'status' => IssueStatusEnum::Cancelled->value,
        ]);
    }

    public function test_cross_property_issue_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'ISS-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $issue = $this->makeInventoryIssue($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/issues/{$issue->id}")->assertNotFound();
    }

    // ─── Transfers ─────────────────────────────────────────────────────────────

    public function test_admin_can_view_transfers_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/transfers')->assertOk();
    }

    public function test_admin_can_create_transfer(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $locA     = $this->makeInventoryLocation($property, ['location_code' => 'CT-A']);
        $locB     = $this->makeInventoryLocation($property, ['location_code' => 'CT-B']);

        $this->post('/operations/inventory/transfers', [
            'transfer_number'  => 'TRF-CT-001',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
            'lines' => [[
                'item_id'            => $item->id,
                'quantity_requested' => 5,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_transfers', [
            'property_id'     => $property->id,
            'transfer_number' => 'TRF-CT-001',
            'status'          => TransferStatusEnum::Draft->value,
        ]);
    }

    public function test_staff_cannot_create_transfer(): void
    {
        ['property' => $property] = $this->bootStaff();
        $locA = $this->makeInventoryLocation($property, ['location_code' => 'CT-SA']);
        $locB = $this->makeInventoryLocation($property, ['location_code' => 'CT-SB']);

        $this->post('/operations/inventory/transfers', [
            'transfer_number'  => 'UNAUTH',
            'from_location_id' => $locA->id,
            'to_location_id'   => $locB->id,
        ])->assertForbidden();
    }

    public function test_admin_can_cancel_transfer(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $locA     = $this->makeInventoryLocation($property, ['location_code' => 'CT-CA']);
        $locB     = $this->makeInventoryLocation($property, ['location_code' => 'CT-CB']);
        $transfer = $this->makeInventoryTransfer($property, $locA, $locB, $admin, [
            'status' => TransferStatusEnum::Draft->value,
        ]);

        $this->post("/operations/inventory/transfers/{$transfer->id}/cancel")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Transfer cancelled.']);

        $this->assertDatabaseHas('inventory_transfers', [
            'id'     => $transfer->id,
            'status' => TransferStatusEnum::Cancelled->value,
        ]);
    }

    public function test_cross_property_transfer_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'TRF-B01']);
        $adminA    = $this->createPropertyAdmin($propertyA);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propertyA->id);
        $this->seedInventoryPermissions();
        $locA     = $this->makeInventoryLocation($propertyA, ['location_code' => 'CP-A']);
        $locB     = $this->makeInventoryLocation($propertyA, ['location_code' => 'CP-B']);
        $transfer = $this->makeInventoryTransfer($propertyA, $locA, $locB, $adminA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/transfers/{$transfer->id}")->assertNotFound();
    }

    // ─── Adjustments ───────────────────────────────────────────────────────────

    public function test_admin_can_view_adjustments_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/adjustments')->assertOk();
    }

    public function test_admin_can_create_adjustment(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $category = $this->makeInventoryCategory($property);
        $unit     = $this->makeInventoryUnit($property);
        $item     = $this->makeInventoryItem($property, $category, $unit);
        $location = $this->makeInventoryLocation($property);

        $this->post('/operations/inventory/adjustments', [
            'adjustment_number' => 'ADJ-CT-001',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'reason'            => 'Annual count',
            'lines' => [[
                'item_id'           => $item->id,
                'quantity_system'   => 0,
                'quantity_actual'   => 5,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_adjustments', [
            'property_id'       => $property->id,
            'adjustment_number' => 'ADJ-CT-001',
            'status'            => AdjustmentStatusEnum::Draft->value,
        ]);
    }

    public function test_staff_cannot_create_adjustment(): void
    {
        ['property' => $property] = $this->bootStaff();
        $location = $this->makeInventoryLocation($property);

        $this->post('/operations/inventory/adjustments', [
            'adjustment_number' => 'UNAUTH',
            'location_id'       => $location->id,
            'adjustment_type'   => AdjustmentTypeEnum::StockTake->value,
            'reason'            => 'Test',
        ])->assertForbidden();
    }

    public function test_admin_can_submit_adjustment(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $location   = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $location, [
            'status' => AdjustmentStatusEnum::Draft->value,
        ]);

        $this->post("/operations/inventory/adjustments/{$adjustment->id}/submit")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Adjustment submitted for approval.']);

        $this->assertDatabaseHas('inventory_adjustments', [
            'id'     => $adjustment->id,
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);
    }

    public function test_admin_can_reject_adjustment(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $location   = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $location, [
            'status' => AdjustmentStatusEnum::Submitted->value,
        ]);

        $this->post("/operations/inventory/adjustments/{$adjustment->id}/reject", [
            'rejection_reason' => 'Variance too large',
        ])->assertOk()
          ->assertJsonFragment(['message' => 'Adjustment rejected.']);

        $this->assertDatabaseHas('inventory_adjustments', [
            'id'               => $adjustment->id,
            'status'           => AdjustmentStatusEnum::Rejected->value,
            'rejection_reason' => 'Variance too large',
        ]);
    }

    public function test_admin_can_cancel_adjustment(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $location   = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $location, [
            'status' => AdjustmentStatusEnum::Draft->value,
        ]);

        $this->post("/operations/inventory/adjustments/{$adjustment->id}/cancel")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Adjustment cancelled.']);

        $this->assertDatabaseHas('inventory_adjustments', [
            'id'     => $adjustment->id,
            'status' => AdjustmentStatusEnum::Cancelled->value,
        ]);
    }

    public function test_cross_property_adjustment_is_not_found_by_other_property(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'ADJ-B01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $location   = $this->makeInventoryLocation($propertyA);
        $adjustment = $this->makeInventoryAdjustment($propertyA, $location);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);
        $this->seedInventoryPermissions();

        $this->get("/operations/inventory/adjustments/{$adjustment->id}")->assertNotFound();
    }

    // ─── Stock Cards ───────────────────────────────────────────────────────────

    public function test_admin_can_view_stock_cards_index(): void
    {
        $this->bootAdmin();

        $this->get('/operations/inventory/stock-cards')->assertOk();
    }

    public function test_staff_cannot_view_stock_cards(): void
    {
        $this->bootStaff();

        $this->get('/operations/inventory/stock-cards')->assertForbidden();
    }
}
