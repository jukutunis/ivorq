<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\WorkOrder;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class EngineeringPolicyTest extends TestCase
{
    use RefreshDatabase, CreatesEngineeringData;

    // ─────────────────────────────────────────────────────────────────────────
    // Shared setup helper
    // ─────────────────────────────────────────────────────────────────────────

    private function bootAdmin(): array
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->seedEngineeringPermissions();
        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        return compact('company', 'property', 'admin');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderPolicy — viewAny / view
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_policy_property_admin_can_view_any(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $this->assertTrue(Gate::inspect('viewAny', WorkOrder::class)->allowed());
    }

    public function test_work_order_policy_staff_cannot_view_any(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedEngineeringPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('viewAny', WorkOrder::class)->denied());
    }

    public function test_work_order_policy_property_admin_can_view_own_work_order(): void
    {
        ['property' => $property] = $this->bootAdmin();

        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('view', $wo)->allowed());
    }

    public function test_work_order_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'WO-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $wo = $this->makeWorkOrderModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $wo)->denied());
    }

    public function test_work_order_policy_super_admin_can_view_any_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $wo = $this->makeWorkOrderModel($propertyA);

        $this->actingAs($superAdmin);
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $this->assertTrue(Gate::inspect('view', $wo)->allowed());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderPolicy — create / update / delete
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_policy_property_admin_can_create(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('create', WorkOrder::class)->allowed());
    }

    public function test_work_order_policy_staff_cannot_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedEngineeringPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('create', WorkOrder::class)->denied());
    }

    public function test_work_order_policy_property_admin_can_update(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('update', $wo)->allowed());
    }

    public function test_work_order_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'WO-PB02']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $wo = $this->makeWorkOrderModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $wo)->denied());
    }

    public function test_work_order_policy_property_admin_can_delete(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('delete', $wo)->allowed());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WorkOrderPolicy — assign / approve / changeStatus
    // ─────────────────────────────────────────────────────────────────────────

    public function test_work_order_policy_property_admin_can_assign(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('assign', $wo)->allowed());
    }

    public function test_work_order_policy_property_admin_can_approve(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('approve', $wo)->allowed());
    }

    public function test_work_order_policy_property_admin_can_change_status(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('changeStatus', $wo)->allowed());
    }

    public function test_work_order_policy_staff_cannot_change_status(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedEngineeringPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property);

        $this->assertTrue(Gate::inspect('changeStatus', $wo)->denied());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PreventiveMaintenancePolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pm_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', PreventiveMaintenance::class)->allowed());
    }

    public function test_pm_policy_staff_cannot_view_any(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedEngineeringPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('viewAny', PreventiveMaintenance::class)->denied());
    }

    public function test_pm_policy_property_admin_can_view_own_pm(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $pm = $this->makePmModel($property);

        $this->assertTrue(Gate::inspect('view', $pm)->allowed());
    }

    public function test_pm_policy_denies_cross_property_view(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'PM-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $pm = $this->makePmModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('view', $pm)->denied());
    }

    public function test_pm_policy_property_admin_can_generate_task(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $pm = $this->makePmModel($property);

        $this->assertTrue(Gate::inspect('generateTask', $pm)->allowed());
    }

    public function test_pm_policy_property_admin_can_delete(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $pm = $this->makePmModel($property);

        $this->assertTrue(Gate::inspect('delete', $pm)->allowed());
    }

    public function test_pm_policy_super_admin_can_access_any_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $pm = $this->makePmModel($propertyA);

        $this->actingAs($superAdmin);
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $this->assertTrue(Gate::inspect('update', $pm)->allowed());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AssetRequestPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_asset_request_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', AssetRequest::class)->allowed());
    }

    public function test_asset_request_policy_staff_cannot_view_any(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedEngineeringPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('viewAny', AssetRequest::class)->denied());
    }

    public function test_asset_request_policy_property_admin_can_approve(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $req = $this->makeAssetRequestModel($property, $admin);

        $this->assertTrue(Gate::inspect('approve', $req)->allowed());
    }

    public function test_asset_request_policy_property_admin_can_reject(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $req = $this->makeAssetRequestModel($property, $admin);

        $this->assertTrue(Gate::inspect('reject', $req)->allowed());
    }

    public function test_asset_request_policy_property_admin_can_fulfill(): void
    {
        ['property' => $property, 'admin' => $admin] = $this->bootAdmin();
        $req = $this->makeAssetRequestModel($property, $admin);

        $this->assertTrue(Gate::inspect('fulfill', $req)->allowed());
    }

    public function test_asset_request_policy_denies_cross_property_approve(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'AR-PB01']);
        $adminA    = $this->createPropertyAdmin($propertyA);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $this->actingAs($adminA);
        $req = $this->makeAssetRequestModel($propertyA, $adminA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('approve', $req)->denied());
    }

    public function test_asset_request_policy_staff_cannot_approve(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');
        $admin    = $this->createPropertyAdmin($property);

        $this->seedEngineeringPermissions();
        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);
        $req = $this->makeAssetRequestModel($property, $admin);

        $this->actingAs($staff);

        $this->assertTrue(Gate::inspect('approve', $req)->denied());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EngineeringChecklistPolicy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_checklist_policy_property_admin_can_view_any(): void
    {
        $this->bootAdmin();
        $this->assertTrue(Gate::inspect('viewAny', EngineeringChecklist::class)->allowed());
    }

    public function test_checklist_policy_staff_cannot_view_any(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $staff    = $this->createUser($property, 'staff');

        $this->seedEngineeringPermissions();
        $this->actingAs($staff);
        app(CurrentPropertyService::class)->setId($property->id);

        $this->assertTrue(Gate::inspect('viewAny', EngineeringChecklist::class)->denied());
    }

    public function test_checklist_policy_property_admin_can_crud(): void
    {
        ['property' => $property] = $this->bootAdmin();
        $checklist = $this->makeChecklistModel($property);

        $this->assertTrue(Gate::inspect('view',   $checklist)->allowed());
        $this->assertTrue(Gate::inspect('update', $checklist)->allowed());
        $this->assertTrue(Gate::inspect('delete', $checklist)->allowed());
    }

    public function test_checklist_policy_denies_cross_property_update(): void
    {
        $company   = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company, ['code' => 'CL-PB01']);
        $adminB    = $this->createPropertyAdmin($propertyB);

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $checklist = $this->makeChecklistModel($propertyA);

        $this->actingAs($adminB);
        app(CurrentPropertyService::class)->setId($propertyB->id);

        $this->assertTrue(Gate::inspect('update', $checklist)->denied());
        $this->assertTrue(Gate::inspect('delete', $checklist)->denied());
    }

    public function test_checklist_policy_super_admin_can_update_any_property(): void
    {
        $company    = $this->createCompany();
        $propertyA  = $this->createProperty($company);
        $superAdmin = $this->createSuperAdmin();

        $this->seedEngineeringPermissions();

        app(CurrentPropertyService::class)->setId($propertyA->id);
        $checklist = $this->makeChecklistModel($propertyA);

        $this->actingAs($superAdmin);
        app(CurrentPropertyService::class)->setId($propertyA->id);

        $this->assertTrue(Gate::inspect('update', $checklist)->allowed());
        $this->assertTrue(Gate::inspect('delete', $checklist)->allowed());
    }
}
