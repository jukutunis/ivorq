<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Models\Task;
use Modules\Operations\Purchasing\Services\PurchaseOrderService;
use Modules\Foundation\User\Models\User;

class PurchasingTaskIntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_issuing_po_creates_task()
    {
        $property = $this->createProperty($this->createCompany());
        $user = $this->createUser($property);

        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST',
            'name' => 'Test',
        ]);
        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-04',
            'name' => 'Vendor 4',
        ]);

        $department = \Modules\Foundation\Department\Models\Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $pr = \Modules\Operations\Purchasing\Models\PurchaseRequest::create([
            'property_id' => $property->id,
            'request_no' => 'PR-TASK-1',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
            'status' => 'Approved'
        ]);

        $po = PurchaseOrder::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'po_no' => 'PO-003', 'issue_date' => now(), 'expected_delivery_date' => now()->addDays(7),
            'status' => 'Draft',
            'created_by' => $user->id,
        ]);

        $service = app(PurchaseOrderService::class);
        $service->issue($po->id, $user);

        $this->assertDatabaseHas((new Task)->getTable(), [
            'taskable_type' => PurchaseOrder::class,
            'taskable_id' => $po->id,
            'source_module' => 'purchasing',
        ]);
    }
}
