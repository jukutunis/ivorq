<?php

namespace Tests\Feature\Operations\Concerns;

use Modules\Operations\Engineering\Database\Seeders\EngineeringPermissionSeeder;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Enums\PmFrequencyEnum;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait CreatesEngineeringData
{
    use CreatesOperationsData;

    /**
     * Seed Engineering permissions and re-sync property-admin and super-admin roles
     * so they receive all Engineering permissions.
     *
     * Must be called after createPropertyAdmin() / seedPermissionsAndRoles() has
     * already run, since those methods create the roles first.
     */
    protected function seedEngineeringPermissions(): void
    {
        $this->seed(EngineeringPermissionSeeder::class);

        foreach (['property-admin', 'super-admin'] as $roleName) {
            Role::where('name', $roleName)->first()
                ?->syncPermissions(Permission::all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makeWorkOrderModel(Property $property, array $overrides = []): WorkOrder
    {
        static $seq = 0;
        $seq++;

        return WorkOrder::create(array_merge([
            'property_id'       => $property->id,
            'work_order_number' => "WO-POL-{$seq}",
            'title'             => "Policy Work Order {$seq}",
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ], $overrides));
    }

    protected function makePmModel(Property $property, array $overrides = []): PreventiveMaintenance
    {
        static $seq = 0;
        $seq++;

        return PreventiveMaintenance::create(array_merge([
            'property_id' => $property->id,
            'pm_code'     => "PM-POL-{$seq}",
            'title'       => "Policy PM {$seq}",
            'frequency'   => PmFrequencyEnum::Monthly->value,
            'status'      => PmStatusEnum::Active->value,
        ], $overrides));
    }

    protected function makeAssetRequestModel(
        Property $property,
        User     $requester,
        array    $overrides = []
    ): AssetRequest {
        static $seq = 0;
        $seq++;

        return AssetRequest::create(array_merge([
            'property_id'    => $property->id,
            'request_number' => "AR-POL-{$seq}",
            'requester_id'   => $requester->id,
            'title'          => "Policy Asset Request {$seq}",
            'status'         => AssetRequestStatusEnum::Pending->value,
            'priority'       => WorkOrderPriorityEnum::Normal->value,
        ], $overrides));
    }

    protected function makeChecklistModel(Property $property, array $overrides = []): EngineeringChecklist
    {
        static $seq = 0;
        $seq++;

        return EngineeringChecklist::create(array_merge([
            'property_id'    => $property->id,
            'title'          => "Policy Checklist {$seq}",
            'checklist_type' => EngineeringChecklistTypeEnum::WorkOrder->value,
            'is_active'      => true,
        ], $overrides));
    }
}
