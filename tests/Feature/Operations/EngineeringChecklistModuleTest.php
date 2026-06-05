<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Repositories\EngineeringChecklistItemRepository;
use Modules\Operations\Engineering\Repositories\EngineeringChecklistRepository;
use Modules\Operations\Engineering\Services\EngineeringChecklistService;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class EngineeringChecklistModuleTest extends TestCase
{
    use RefreshDatabase, CreatesEngineeringData;

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_create_checklist_stores_in_database(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(EngineeringChecklistService::class);
        $checklist = $service->create([
            'property_id'    => $property->id,
            'title'          => 'Daily Pump Inspection',
            'checklist_type' => EngineeringChecklistTypeEnum::PreventiveMaintenance->value,
            'is_active'      => true,
        ]);

        $this->assertInstanceOf(EngineeringChecklist::class, $checklist);
        $this->assertDatabaseHas('engineering_checklists', [
            'property_id'    => $property->id,
            'title'          => 'Daily Pump Inspection',
            'checklist_type' => 'preventive_maintenance',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_checklist_changes_title_and_description(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklistModel($property);

        $updated = $service->update($checklist->id, [
            'title'       => 'Updated Title',
            'description' => 'Updated description',
        ]);

        $this->assertSame('Updated Title', $updated->title);
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

        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklistModel($property);

        $this->assertTrue($service->delete($checklist->id));
        $this->assertSoftDeleted('engineering_checklists', ['id' => $checklist->id]);
    }

    // ── Add item ──────────────────────────────────────────────────────────────

    public function test_add_item_creates_checklist_item_with_correct_checklist_id(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklistModel($property);

        $item = $service->addItem($checklist->id, [
            'property_id' => $property->id,
            'item_text'   => 'Check oil level',
            'sort_order'  => 1,
            'is_required' => true,
        ]);

        $this->assertSame($checklist->id, $item->engineering_checklist_id);
        $this->assertDatabaseHas('engineering_checklist_items', [
            'engineering_checklist_id' => $checklist->id,
            'item_text'                => 'Check oil level',
            'sort_order'               => 1,
            'is_required'              => 1,
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

        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklistModel($property);

        $item    = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Original text', 'sort_order' => 0]);
        $updated = $service->updateItem($item->id, ['item_text' => 'Updated text']);

        $this->assertSame('Updated text', $updated->item_text);
        $this->assertDatabaseHas('engineering_checklist_items', [
            'id'        => $item->id,
            'item_text' => 'Updated text',
        ]);
    }

    // ── Delete item ───────────────────────────────────────────────────────────

    public function test_delete_checklist_item_removes_record(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklistModel($property);

        $item = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'To be deleted', 'sort_order' => 0]);

        $this->assertTrue($service->deleteItem($item->id));
        $this->assertDatabaseMissing('engineering_checklist_items', ['id' => $item->id]);
    }

    // ── Reorder items ─────────────────────────────────────────────────────────

    public function test_reorder_items_updates_sort_order(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $service   = app(EngineeringChecklistService::class);
        $checklist = $this->makeChecklistModel($property);

        $item1 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step A', 'sort_order' => 0]);
        $item2 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step B', 'sort_order' => 1]);
        $item3 = $service->addItem($checklist->id, ['property_id' => $property->id, 'item_text' => 'Step C', 'sort_order' => 2]);

        $service->reorderItems([$item3->id, $item1->id, $item2->id]);

        $itemRepo  = app(EngineeringChecklistItemRepository::class);
        $reordered = $itemRepo->forChecklist($checklist->id);

        $this->assertSame($item3->id, $reordered[0]->id);
        $this->assertSame($item1->id, $reordered[1]->id);
        $this->assertSame($item2->id, $reordered[2]->id);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────────

    public function test_checklist_title_can_be_reused_across_properties(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'CL-PB50']);
        $admin     = $this->createPropertyAdmin($propertyA);

        $this->actingAs($admin);

        $repo = app(EngineeringChecklistRepository::class);

        $a = $repo->create(['property_id' => $propertyA->id, 'title' => 'Shared Title', 'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value, 'is_active' => true]);
        $b = $repo->create(['property_id' => $propertyB->id, 'title' => 'Shared Title', 'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value, 'is_active' => true]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertDatabaseCount('engineering_checklists', 2);
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public function test_active_filter_returns_only_active_checklists(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(EngineeringChecklistRepository::class);
        $repo->create(['property_id' => $property->id, 'title' => 'Active',   'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value, 'is_active' => true]);
        $repo->create(['property_id' => $property->id, 'title' => 'Inactive', 'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value, 'is_active' => false]);

        $active = $repo->active();

        $this->assertCount(1, $active);
        $this->assertSame('Active', $active->first()->title);
    }

    public function test_by_type_filter_returns_only_matching_checklists(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $repo = app(EngineeringChecklistRepository::class);
        $repo->create(['property_id' => $property->id, 'title' => 'WO Checklist', 'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value,            'is_active' => true]);
        $repo->create(['property_id' => $property->id, 'title' => 'PM Checklist', 'checklist_type' => EngineeringChecklistTypeEnum::PreventiveMaintenance->value, 'is_active' => true]);

        $woLists = $repo->byType(EngineeringChecklistTypeEnum::WorkOrder);

        $this->assertCount(1, $woLists);
        $this->assertSame('WO Checklist', $woLists->first()->title);
    }

    // ── Cross-property isolation ──────────────────────────────────────────────

    public function test_cross_property_checklist_policy_denies_update_and_delete(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'CL-PB51']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $checklist = $this->makeChecklistModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $checklist)->denied());
        $this->assertTrue(Gate::inspect('delete', $checklist)->denied());
    }
}
