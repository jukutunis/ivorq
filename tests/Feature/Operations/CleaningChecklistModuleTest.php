<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Repositories\ChecklistItemRepository;
use Modules\Operations\Housekeeping\Repositories\ChecklistRepository;
use Modules\Operations\Housekeeping\Services\ChecklistService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesOperationsData;
use Tests\TestCase;

class CleaningChecklistModuleTest extends TestCase
{
    use RefreshDatabase, CreatesOperationsData;

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_checklist_stores_in_database(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create([
            'property_id' => $property->id,
            'name'        => 'Checkout Checklist',
            'task_type'   => 'checkout_cleaning',
            'is_active'   => true,
        ]);

        $this->assertInstanceOf(CleaningChecklist::class, $checklist);
        $this->assertDatabaseHas('cleaning_checklists', [
            'property_id' => $property->id,
            'name'        => 'Checkout Checklist',
            'task_type'   => 'checkout_cleaning',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_checklist_changes_name_and_description(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Old Name', 'is_active' => true]);

        $updated = $service->update($checklist->id, [
            'name'        => 'New Name',
            'description' => 'Updated description',
        ]);

        $this->assertSame('New Name', $updated->name);
        $this->assertSame('Updated description', $updated->description);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_checklist_soft_deletes(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Delete Me', 'is_active' => true]);

        $this->assertTrue($service->delete($checklist->id));
        $this->assertSoftDeleted('cleaning_checklists', ['id' => $checklist->id]);
    }

    // ── Add item ──────────────────────────────────────────────────────────────

    public function test_add_item_creates_checklist_item(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Item Test', 'is_active' => true]);

        $item = $service->addItem($checklist->id, [
            'property_id' => $property->id,
            'item_text'   => 'Strip bed linens',
            'sort_order'  => 1,
            'is_required' => true,
        ]);

        $this->assertDatabaseHas('checklist_items', [
            'checklist_id' => $checklist->id,
            'item_text'    => 'Strip bed linens',
            'sort_order'   => 1,
            'is_required'  => 1,
        ]);
    }

    // ── Update item ───────────────────────────────────────────────────────────

    public function test_update_checklist_item_changes_text(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Update Item Test', 'is_active' => true]);

        $item    = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Original text', 'sort_order' => 0]);
        $updated = $service->updateItem($item->id, ['item_text' => 'Updated text']);

        $this->assertSame('Updated text', $updated->item_text);
        $this->assertDatabaseHas('checklist_items', ['id' => $item->id, 'item_text' => 'Updated text']);
    }

    // ── Delete item ───────────────────────────────────────────────────────────

    public function test_delete_checklist_item_removes_record(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Delete Item Test', 'is_active' => true]);

        $item = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'To be deleted', 'sort_order' => 0]);

        $this->assertTrue($service->deleteItem($item->id));
        $this->assertDatabaseMissing('checklist_items', ['id' => $item->id]);
    }

    // ── Reorder items ─────────────────────────────────────────────────────────

    public function test_reorder_items_updates_sort_order(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(ChecklistService::class);
        $checklist = $service->create(['property_id' => $property->id, 'name' => 'Reorder Test', 'is_active' => true]);

        $item1 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step A', 'sort_order' => 0]);
        $item2 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step B', 'sort_order' => 1]);
        $item3 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step C', 'sort_order' => 2]);

        $service->reorderItems([$item3->id, $item1->id, $item2->id]);

        $itemRepo  = app(ChecklistItemRepository::class);
        $reordered = $itemRepo->forChecklist($checklist->id);

        $this->assertSame($item3->id, $reordered[0]->id);
        $this->assertSame($item1->id, $reordered[1]->id);
        $this->assertSame($item2->id, $reordered[2]->id);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_checklist_name_must_be_unique_per_property(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(ChecklistRepository::class);
        $repo->create(['property_id' => $property->id, 'name' => 'Duplicate Name', 'is_active' => true]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $repo->create(['property_id' => $property->id, 'name' => 'Duplicate Name', 'is_active' => true]);
    }

    public function test_checklist_name_can_be_reused_across_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB50']);
        $admin     = $this->createPropertyAdmin($propertyA);

        $this->actingAs($admin);
        $repo = app(ChecklistRepository::class);

        $a = $repo->create(['property_id' => $propertyA->id, 'name' => 'Shared Name', 'is_active' => true]);
        $b = $repo->create(['property_id' => $propertyB->id, 'name' => 'Shared Name', 'is_active' => true]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertDatabaseCount('cleaning_checklists', 2);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_checklist_policy_denies_update_and_delete(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PB51']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedPermissionsAndRoles();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $checklist = CleaningChecklist::create([
            'property_id' => $propertyA->id,
            'name'        => 'Cross-Property Checklist',
            'is_active'   => true,
        ]);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $checklist)->denied());
        $this->assertTrue(Gate::inspect('delete', $checklist)->denied());
    }
}
