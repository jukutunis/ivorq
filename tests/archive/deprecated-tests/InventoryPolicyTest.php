<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Inventory\Models\InventoryAdjustment;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesInventoryData;
use Tests\TestCase;

class InventoryPolicyTest extends TestCase
{
    use RefreshDatabase, CreatesInventoryData;

    // ─────────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bootAdmin(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->seedInventoryPermissions();
        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    private function bootStaff(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedInventoryPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'staff');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryCategoryPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_category_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryCategory::class)->allowed());
    }

    public function test_category_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryCategory::class)->denied());
    }

    public function test_category_policy_admin_can_view_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $cat = $this->makeInventoryCategory($property);
        $this->assertTrue(Gate::inspect('view', $cat)->allowed());
    }

    public function test_category_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propA     = $this->createProperty($company);
        $propB     = $this->createProperty($company, ['code' => 'IC-B01']);
        $adminB    = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $cat = $this->makeInventoryCategory($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('view', $cat)->denied());
    }

    public function test_category_policy_admin_can_create(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('create', InventoryCategory::class)->allowed());
    }

    public function test_category_policy_staff_cannot_create(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('create', InventoryCategory::class)->denied());
    }

    public function test_category_policy_admin_can_update(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $cat = $this->makeInventoryCategory($property);
        $this->assertTrue(Gate::inspect('update', $cat)->allowed());
    }

    public function test_category_policy_denies_cross_property_update(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IC-B02']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $cat = $this->makeInventoryCategory($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('update', $cat)->denied());
    }

    public function test_category_policy_admin_can_delete(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $cat = $this->makeInventoryCategory($property);
        $this->assertTrue(Gate::inspect('delete', $cat)->allowed());
    }

    public function test_category_policy_super_admin_can_view_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $cat = $this->makeInventoryCategory($propA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('view',   $cat)->allowed());
        $this->assertTrue(Gate::inspect('update', $cat)->allowed());
        $this->assertTrue(Gate::inspect('delete', $cat)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryUnitPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_unit_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryUnit::class)->allowed());
    }

    public function test_unit_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryUnit::class)->denied());
    }

    public function test_unit_policy_admin_can_crud_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $unit = $this->makeInventoryUnit($property);

        $this->assertTrue(Gate::inspect('view',   $unit)->allowed());
        $this->assertTrue(Gate::inspect('update', $unit)->allowed());
        $this->assertTrue(Gate::inspect('delete', $unit)->allowed());
    }

    public function test_unit_policy_denies_cross_property_update(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IU-B01']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $unit = $this->makeInventoryUnit($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('update', $unit)->denied());
        $this->assertTrue(Gate::inspect('delete', $unit)->denied());
    }

    public function test_unit_policy_super_admin_can_manage_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $unit = $this->makeInventoryUnit($propA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('update', $unit)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryLocationPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_location_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryLocation::class)->allowed());
    }

    public function test_location_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryLocation::class)->denied());
    }

    public function test_location_policy_admin_can_crud_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $loc = $this->makeInventoryLocation($property);

        $this->assertTrue(Gate::inspect('view',   $loc)->allowed());
        $this->assertTrue(Gate::inspect('update', $loc)->allowed());
        $this->assertTrue(Gate::inspect('delete', $loc)->allowed());
    }

    public function test_location_policy_denies_cross_property_update(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IL-B01']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $loc = $this->makeInventoryLocation($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('update', $loc)->denied());
    }

    public function test_location_policy_super_admin_can_manage_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $loc = $this->makeInventoryLocation($propA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('update', $loc)->allowed());
        $this->assertTrue(Gate::inspect('delete', $loc)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryItemPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_item_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryItem::class)->allowed());
    }

    public function test_item_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryItem::class)->denied());
    }

    public function test_item_policy_admin_can_crud_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $cat  = $this->makeInventoryCategory($property);
        $unit = $this->makeInventoryUnit($property);
        $item = $this->makeInventoryItem($property, $cat, $unit);

        $this->assertTrue(Gate::inspect('view',   $item)->allowed());
        $this->assertTrue(Gate::inspect('update', $item)->allowed());
        $this->assertTrue(Gate::inspect('delete', $item)->allowed());
    }

    public function test_item_policy_denies_cross_property_view(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'II-B01']);
        $adminA  = $this->createPropertyAdmin($propA);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $cat  = $this->makeInventoryCategory($propA);
        $unit = $this->makeInventoryUnit($propA);
        $item = $this->makeInventoryItem($propA, $cat, $unit);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('view',   $item)->denied());
        $this->assertTrue(Gate::inspect('update', $item)->denied());
    }

    public function test_item_policy_super_admin_can_manage_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $adminA     = $this->createPropertyAdmin($propA);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $cat  = $this->makeInventoryCategory($propA);
        $unit = $this->makeInventoryUnit($propA);
        $item = $this->makeInventoryItem($propA, $cat, $unit);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('view',   $item)->allowed());
        $this->assertTrue(Gate::inspect('update', $item)->allowed());
        $this->assertTrue(Gate::inspect('delete', $item)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryReceiptPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_receipt_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryReceipt::class)->allowed());
    }

    public function test_receipt_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryReceipt::class)->denied());
    }

    public function test_receipt_policy_admin_can_view_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $receipt = $this->makeInventoryReceipt($property);
        $this->assertTrue(Gate::inspect('view', $receipt)->allowed());
    }

    public function test_receipt_policy_denies_cross_property_view(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IR-B01']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $receipt = $this->makeInventoryReceipt($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('view', $receipt)->denied());
    }

    public function test_receipt_policy_admin_can_post(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $receipt = $this->makeInventoryReceipt($property);
        $this->assertTrue(Gate::inspect('post', $receipt)->allowed());
    }

    public function test_receipt_policy_staff_cannot_post(): void
    {
        ['property' => $property, 'staff' => $staff] = $this->bootStaff();
        $receipt = $this->makeInventoryReceipt($property);
        $this->assertTrue(Gate::inspect('post', $receipt)->denied());
    }

    public function test_receipt_policy_denies_cross_property_post(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IR-B02']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $receipt = $this->makeInventoryReceipt($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('post', $receipt)->denied());
    }

    public function test_receipt_policy_admin_can_cancel(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $receipt = $this->makeInventoryReceipt($property);
        $this->assertTrue(Gate::inspect('cancel', $receipt)->allowed());
    }

    public function test_receipt_policy_super_admin_can_post_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $receipt = $this->makeInventoryReceipt($propA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('post',   $receipt)->allowed());
        $this->assertTrue(Gate::inspect('cancel', $receipt)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryIssuePolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_issue_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryIssue::class)->allowed());
    }

    public function test_issue_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryIssue::class)->denied());
    }

    public function test_issue_policy_admin_can_view_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $issue = $this->makeInventoryIssue($property);
        $this->assertTrue(Gate::inspect('view', $issue)->allowed());
    }

    public function test_issue_policy_denies_cross_property_view(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IIS-B01']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $issue = $this->makeInventoryIssue($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('view', $issue)->denied());
    }

    public function test_issue_policy_admin_can_post(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $issue = $this->makeInventoryIssue($property);
        $this->assertTrue(Gate::inspect('post', $issue)->allowed());
    }

    public function test_issue_policy_staff_cannot_post(): void
    {
        ['property' => $property, 'staff' => $staff] = $this->bootStaff();
        $issue = $this->makeInventoryIssue($property);
        $this->assertTrue(Gate::inspect('post', $issue)->denied());
    }

    public function test_issue_policy_denies_cross_property_post(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IIS-B02']);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $issue = $this->makeInventoryIssue($propA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('post', $issue)->denied());
    }

    public function test_issue_policy_super_admin_can_post_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        app(CurrentPropertyService::class)->setId($propA->id);
        $issue = $this->makeInventoryIssue($propA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('post', $issue)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryTransferPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_transfer_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryTransfer::class)->allowed());
    }

    public function test_transfer_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryTransfer::class)->denied());
    }

    public function test_transfer_policy_admin_can_view_own(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $from     = $this->makeInventoryLocation($property);
        $to       = $this->makeInventoryLocation($property);
        $transfer = $this->makeInventoryTransfer($property, $from, $to, $admin);

        $this->assertTrue(Gate::inspect('view', $transfer)->allowed());
    }

    public function test_transfer_policy_denies_cross_property_view(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IT-B01']);
        $adminA  = $this->createPropertyAdmin($propA);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $from     = $this->makeInventoryLocation($propA);
        $to       = $this->makeInventoryLocation($propA);
        $transfer = $this->makeInventoryTransfer($propA, $from, $to, $adminA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('view', $transfer)->denied());
    }

    public function test_transfer_policy_admin_can_complete(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $from     = $this->makeInventoryLocation($property);
        $to       = $this->makeInventoryLocation($property);
        $transfer = $this->makeInventoryTransfer($property, $from, $to, $admin);

        $this->assertTrue(Gate::inspect('complete', $transfer)->allowed());
    }

    public function test_transfer_policy_staff_cannot_complete(): void
    {
        ['property' => $property, 'staff' => $staff] = $this->bootStaff();
        $admin    = $this->createPropertyAdmin($property);
        $from     = $this->makeInventoryLocation($property);
        $to       = $this->makeInventoryLocation($property);
        $transfer = $this->makeInventoryTransfer($property, $from, $to, $admin);

        $this->assertTrue(Gate::inspect('complete', $transfer)->denied());
    }

    public function test_transfer_policy_denies_cross_property_complete(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IT-B02']);
        $adminA  = $this->createPropertyAdmin($propA);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $from     = $this->makeInventoryLocation($propA);
        $to       = $this->makeInventoryLocation($propA);
        $transfer = $this->makeInventoryTransfer($propA, $from, $to, $adminA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('complete', $transfer)->denied());
    }

    public function test_transfer_policy_super_admin_can_complete_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $adminA     = $this->createPropertyAdmin($propA);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $from     = $this->makeInventoryLocation($propA);
        $to       = $this->makeInventoryLocation($propA);
        $transfer = $this->makeInventoryTransfer($propA, $from, $to, $adminA);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('complete', $transfer)->allowed());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // InventoryAdjustmentPolicy
    // ═════════════════════════════════════════════════════════════════════════

    public function test_adjustment_policy_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', InventoryAdjustment::class)->allowed());
    }

    public function test_adjustment_policy_staff_cannot_view_any(): void
    {
        $this->bootStaff();
        $this->assertTrue(Gate::inspect('viewAny', InventoryAdjustment::class)->denied());
    }

    public function test_adjustment_policy_admin_can_view_own(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $loc        = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $loc);

        $this->assertTrue(Gate::inspect('view', $adjustment)->allowed());
    }

    public function test_adjustment_policy_denies_cross_property_view(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IA-B01']);
        $adminA  = $this->createPropertyAdmin($propA);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $loc        = $this->makeInventoryLocation($propA);
        $adjustment = $this->makeInventoryAdjustment($propA, $loc);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('view', $adjustment)->denied());
    }

    public function test_adjustment_policy_admin_can_submit(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $loc        = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $loc);

        $this->assertTrue(Gate::inspect('submit', $adjustment)->allowed());
    }

    public function test_adjustment_policy_staff_cannot_submit(): void
    {
        ['property' => $property, 'staff' => $staff] = $this->bootStaff();
        $loc        = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $loc);

        $this->assertTrue(Gate::inspect('submit', $adjustment)->denied());
    }

    public function test_adjustment_policy_admin_can_approve(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $loc        = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $loc);

        $this->assertTrue(Gate::inspect('approve', $adjustment)->allowed());
        $this->assertTrue(Gate::inspect('reject',  $adjustment)->allowed());
    }

    public function test_adjustment_policy_staff_cannot_approve(): void
    {
        ['property' => $property, 'staff' => $staff] = $this->bootStaff();
        $loc        = $this->makeInventoryLocation($property);
        $adjustment = $this->makeInventoryAdjustment($property, $loc);

        $this->assertTrue(Gate::inspect('approve', $adjustment)->denied());
        $this->assertTrue(Gate::inspect('reject',  $adjustment)->denied());
    }

    public function test_adjustment_policy_denies_cross_property_approve(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'IA-B02']);
        $adminA  = $this->createPropertyAdmin($propA);
        $adminB  = $this->createPropertyAdmin($propB);

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $loc        = $this->makeInventoryLocation($propA);
        $adjustment = $this->makeInventoryAdjustment($propA, $loc);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propB->id);

        $this->assertTrue(Gate::inspect('approve', $adjustment)->denied());
    }

    public function test_adjustment_policy_super_admin_can_approve_cross_property(): void
    {
        $company    = $this->createCompany();
        $propA      = $this->createProperty($company);
        $adminA     = $this->createPropertyAdmin($propA);
        $superAdmin = $this->createSuperAdmin();

        $this->seedInventoryPermissions();
        $this->actingAs($adminA);
        app(CurrentPropertyService::class)->setId($propA->id);

        $loc        = $this->makeInventoryLocation($propA);
        $adjustment = $this->makeInventoryAdjustment($propA, $loc);

        $this->actingAs($superAdmin);

        $this->assertTrue(Gate::inspect('approve', $adjustment)->allowed());
        $this->assertTrue(Gate::inspect('reject',  $adjustment)->allowed());
    }
}
