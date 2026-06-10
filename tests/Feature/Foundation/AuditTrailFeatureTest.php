<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Enums\WorkOrderTypeEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Enums\WorkOrderStatusEnum;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\Feature\Operations\Concerns\CreatesEngineeringData;
use Tests\TestCase;

class AuditTrailFeatureTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData, CreatesEngineeringData;

    public function test_audit_log_created_on_operations_model_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = WorkOrder::create([
            'property_id'       => $property->id,
            'work_order_number' => 'WO-AUDIT-001',
            'title'             => 'Audit Create Test',
            'work_order_type'   => WorkOrderTypeEnum::Corrective->value,
            'priority'          => WorkOrderPriorityEnum::Normal->value,
            'status'            => WorkOrderStatusEnum::Pending->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => WorkOrder::class,
            'auditable_id'   => $wo->id,
            'event'          => 'created',
        ]);
    }

    public function test_audit_log_created_on_operations_model_update(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property);

        $wo->update(['title' => 'Updated Title for Audit']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => WorkOrder::class,
            'auditable_id'   => $wo->id,
            'event'          => 'updated',
        ]);
    }

    public function test_audit_log_created_on_operations_model_delete(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property);

        $wo->delete();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => WorkOrder::class,
            'auditable_id'   => $wo->id,
            'event'          => 'deleted',
        ]);
    }

    public function test_audit_log_created_on_operations_model_restore(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $wo = $this->makeWorkOrderModel($property);
        $wo->delete();
        $wo->restore();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => WorkOrder::class,
            'auditable_id'   => $wo->id,
            'event'          => 'restored',
        ]);
    }
}
