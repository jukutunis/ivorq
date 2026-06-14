<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryUnit;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_create_purchase_order()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-01',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);
        
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST',
            'name' => 'Test',
        ]);

        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-01',
            'name' => 'Vendor 1',
        ]);

        $po = PurchaseOrder::create([
            'property_id' => $property->id,
            'purchase_request_id' => $pr->id,
            'vendor_id' => $vendor->id,
            'po_no' => 'PO-001', 'issue_date' => now(), 'expected_delivery_date' => now()->addDays(7),
            'total_amount' => 500,
        ]);

        $unit = InventoryUnit::create(['property_id' => $property->id, 'code' => 'PCS', 'name' => 'Pieces']);

        $line = $po->lines()->create([
            'description' => 'Item 1',
            'unit_id' => $unit->id,
            'quantity_ordered' => 2,
            'unit_cost' => 250,
            'line_total' => 500,
        ]);

        $this->assertNotNull($po->id);
        $this->assertEquals('PO-001', $po->po_no);
        $this->assertEquals(500, $po->total_amount);
        $this->assertEquals($pr->id, $po->purchase_request_id);
        
        $this->assertCount(1, $po->lines);
        $this->assertEquals('Item 1', $po->lines->first()->description);
    }

    public function test_purchase_order_belongs_to_vendor()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST',
            'name' => 'Test',
        ]);
        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-02',
            'name' => 'Vendor 2',
        ]);

        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);
        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-02',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);

        $po = PurchaseOrder::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'po_no' => 'PO-002', 'issue_date' => now(), 'expected_delivery_date' => now()->addDays(7),
        ]);

        $this->assertEquals($vendor->id, $po->vendor->id);
    }
}
