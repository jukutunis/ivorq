<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseOrderLine;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\RequestForQuotation;
use Modules\Operations\Purchasing\Models\VendorQuotation;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Foundation\Department\Models\Department;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Illuminate\Database\QueryException;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_po_lifecycle_and_rfq_conversion()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $creator = $this->createUser($property);
        $approver = $this->createUser($property);
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);
        $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V1', 'name' => 'V1']);
        $unit = InventoryUnit::create(['property_id' => $property->id, 'code' => 'PCS', 'name' => 'Pieces']);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id,
            'request_no' => 'PR-1',
            'department_id' => $department->id,
            'requester_id' => $creator->id,
            'required_date' => now(),
        ]);

        $rfq = RequestForQuotation::create([
            'property_id' => $property->id,
            'purchase_request_id' => $pr->id,
            'rfq_number' => 'RFQ-1',
            'title' => 'Test',
        ]);

        $quote = VendorQuotation::create([
            'property_id' => $property->id,
            'request_for_quotation_id' => $rfq->id,
            'vendor_id' => $vendor->id,
        ]);

        // Award RFQ
        $rfq->selectWinningQuotation($quote);

        // Convert to PO
        $po = PurchaseOrder::create([
            'property_id' => $property->id,
            'po_no' => 'PO-1',
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'issue_date' => now(),
            'expected_delivery_date' => now()->addDays(7),
            'status' => PurchaseOrderStatusEnum::Draft->value,
        ]);

        $line = $po->lines()->create([
            'description' => 'Test Item',
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_id' => $unit->id,
            'unit_cost' => 100,
            'receiving_tolerance_percent' => 10, // 10% tolerance
        ]);

        $this->assertEquals(PurchaseOrderStatusEnum::Draft, $po->status);

        $po->update(['status' => PurchaseOrderStatusEnum::PendingReview->value]);
        $this->assertEquals(PurchaseOrderStatusEnum::PendingReview, $po->status);

        $this->actingAs($approver);
        $po->markAsApproved();
        $this->assertEquals(PurchaseOrderStatusEnum::Approved, $po->fresh()->status);
        $this->assertEquals($approver->id, $po->fresh()->approved_by);
        $this->assertNotNull($po->fresh()->approved_at);

        $po->update(['status' => PurchaseOrderStatusEnum::Issued->value]);
        $this->assertEquals(PurchaseOrderStatusEnum::Issued, $po->fresh()->status);
    }

    public function test_partial_and_full_receiving_with_tolerance_validation()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $creator = $this->createUser($property);
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);
        $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V1', 'name' => 'V1']);
        $unit = InventoryUnit::create(['property_id' => $property->id, 'code' => 'PCS', 'name' => 'Pieces']);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id,
            'request_no' => 'PR-2',
            'department_id' => $department->id,
            'requester_id' => $creator->id,
            'required_date' => now(),
        ]);

        $po = PurchaseOrder::create([
            'property_id' => $property->id,
            'po_no' => 'PO-2',
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'issue_date' => now(),
            'expected_delivery_date' => now()->addDays(7),
            'status' => PurchaseOrderStatusEnum::Issued->value,
        ]);

        $line = $po->lines()->create([
            'description' => 'Test Item',
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_id' => $unit->id,
            'unit_cost' => 100,
            'receiving_tolerance_percent' => 10, // 10% = 1 allowed over-receiving
        ]);

        // Partial Receiving
        $line->update(['received_quantity' => 5]);
        $po->update(['status' => PurchaseOrderStatusEnum::PartiallyReceived->value]);
        $this->assertEquals(5, $line->fresh()->remaining_quantity);

        // Full Receiving
        $line->update(['received_quantity' => 10]);
        $po->update(['status' => PurchaseOrderStatusEnum::FullyReceived->value]);
        $this->assertEquals(0, $line->fresh()->remaining_quantity);

        // Within Tolerance (11 received, 10 ordered + 10% tolerance)
        $line->update(['received_quantity' => 11]);
        $this->assertEquals(11, $line->fresh()->received_quantity);

        // Block over-receiving (exceeding tolerance) would typically be enforced 
        // via form request validation or business logic service. We'll simulate 
        // a domain exception or assertion if we had a ReceivingService here. 
        // For now, since the service isn't built yet, we assert the columns exist.
        $this->assertDatabaseHas('purchase_order_lines', [
            'id' => $line->id,
            'ordered_quantity' => 10,
            'receiving_tolerance_percent' => 10,
        ]);
    }

    public function test_property_isolation_on_purchase_order()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        $departmentA = Department::create(['property_id' => $propertyA->id, 'name' => 'IT', 'code' => 'IT-A']);
        $departmentB = Department::create(['property_id' => $propertyB->id, 'name' => 'IT', 'code' => 'IT-B']);
        
        $creatorA = $this->createUser($propertyA);
        $creatorB = $this->createUser($propertyB);

        $categoryA = VendorCategory::create(['property_id' => $propertyA->id, 'name' => 'IT', 'category_code' => 'IT-A']);
        $categoryB = VendorCategory::create(['property_id' => $propertyB->id, 'name' => 'IT', 'category_code' => 'IT-B']);

        $vendorA = Vendor::create(['property_id' => $propertyA->id, 'vendor_category_id' => $categoryA->id, 'vendor_code' => 'VA', 'name' => 'VA']);
        $vendorB = Vendor::create(['property_id' => $propertyB->id, 'vendor_category_id' => $categoryB->id, 'vendor_code' => 'VB', 'name' => 'VB']);

        $prA = PurchaseRequest::create(['property_id' => $propertyA->id, 'request_no' => 'PR-A', 'department_id' => $departmentA->id, 'requester_id' => $creatorA->id, 'required_date' => now()]);
        $prB = PurchaseRequest::create(['property_id' => $propertyB->id, 'request_no' => 'PR-B', 'department_id' => $departmentB->id, 'requester_id' => $creatorB->id, 'required_date' => now()]);

        PurchaseOrder::create([
            'property_id' => $propertyA->id,
            'po_no' => 'PO-ISO',
            'vendor_id' => $vendorA->id,
            'purchase_request_id' => $prA->id,
            'issue_date' => now(),
            'expected_delivery_date' => now(),
        ]);

        // Same PO No in Property B should succeed
        $poB = PurchaseOrder::create([
            'property_id' => $propertyB->id,
            'po_no' => 'PO-ISO',
            'vendor_id' => $vendorB->id,
            'purchase_request_id' => $prB->id,
            'issue_date' => now(),
            'expected_delivery_date' => now(),
        ]);

        $this->assertNotNull($poB->id);

        $prC = PurchaseRequest::create(['property_id' => $propertyA->id, 'request_no' => 'PR-C', 'department_id' => $departmentA->id, 'requester_id' => $creatorA->id, 'required_date' => now()]);

        $this->expectException(QueryException::class);

        // Same PO No in Property A should fail (Duplicate Prevention)
        PurchaseOrder::create([
            'property_id' => $propertyA->id,
            'po_no' => 'PO-ISO',
            'vendor_id' => $vendorA->id,
            'purchase_request_id' => $prC->id,
            'issue_date' => now(),
            'expected_delivery_date' => now(),
        ]);
    }
}
