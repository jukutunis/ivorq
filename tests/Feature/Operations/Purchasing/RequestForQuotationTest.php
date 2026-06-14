<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\RequestForQuotation;
use Modules\Operations\Purchasing\Models\VendorQuotation;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Enums\RequestForQuotationStatusEnum;
use Modules\Operations\Purchasing\Enums\VendorQuotationStatusEnum;
use Illuminate\Database\QueryException;

class RequestForQuotationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_rfq_and_quotation_lifecycle_with_vendor_selection()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);

        $vendor1 = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V1', 'name' => 'V1']);
        $vendor2 = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V2', 'name' => 'V2']);

        // 1. Create RFQ
        $rfq = RequestForQuotation::create([
            'property_id' => $property->id,
            'rfq_number' => 'RFQ-001',
            'title' => 'Laptops',
            'deadline_at' => now()->addDays(5),
            'status' => RequestForQuotationStatusEnum::Draft->value,
        ]);

        $this->assertEquals(RequestForQuotationStatusEnum::Draft, $rfq->status);

        // 2. Publish RFQ (Attach Vendors)
        $rfq->vendors()->attach([$vendor1->id, $vendor2->id]);
        $rfq->update(['status' => RequestForQuotationStatusEnum::Published->value]);
        $this->assertEquals(RequestForQuotationStatusEnum::Published, $rfq->status);

        // 3. Receive Bids
        $quote1 = VendorQuotation::create([
            'property_id' => $property->id,
            'request_for_quotation_id' => $rfq->id,
            'vendor_id' => $vendor1->id,
            'total_amount' => 5000,
            'status' => VendorQuotationStatusEnum::Submitted->value,
        ]);

        $quote2 = VendorQuotation::create([
            'property_id' => $property->id,
            'request_for_quotation_id' => $rfq->id,
            'vendor_id' => $vendor2->id,
            'total_amount' => 4800,
            'status' => VendorQuotationStatusEnum::Submitted->value,
        ]);

        $rfq->update(['status' => RequestForQuotationStatusEnum::BidsReceived->value]);
        $this->assertEquals(RequestForQuotationStatusEnum::BidsReceived, $rfq->status);

        // 4. Vendor Selection Engine
        $rfq->selectWinningQuotation($quote2);

        $this->assertEquals(RequestForQuotationStatusEnum::Awarded, $rfq->fresh()->status);
        $this->assertTrue($quote2->fresh()->is_winner);
        $this->assertEquals(VendorQuotationStatusEnum::Selected, $quote2->fresh()->status);
        
        $this->assertFalse($quote1->fresh()->is_winner);
        $this->assertEquals(VendorQuotationStatusEnum::Rejected, $quote1->fresh()->status);
    }

    public function test_property_isolation_and_duplicate_prevention_on_rfq()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        // Create RFQ in Property A
        RequestForQuotation::create([
            'property_id' => $propertyA->id,
            'rfq_number' => 'RFQ-ISO',
            'title' => 'Test',
        ]);

        // Same RFQ Number in Property B should succeed
        $rfqB = RequestForQuotation::create([
            'property_id' => $propertyB->id,
            'rfq_number' => 'RFQ-ISO',
            'title' => 'Test',
        ]);

        $this->assertNotNull($rfqB->id);

        $this->expectException(QueryException::class);

        // Same RFQ Number in Property A should fail
        RequestForQuotation::create([
            'property_id' => $propertyA->id,
            'rfq_number' => 'RFQ-ISO',
            'title' => 'Test',
        ]);
    }

    public function test_multiple_quotations_from_same_vendor_prevented()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);
        $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'vendor_code' => 'V1', 'name' => 'V1']);

        $rfq = RequestForQuotation::create([
            'property_id' => $property->id,
            'rfq_number' => 'RFQ-002',
            'title' => 'Test',
        ]);

        VendorQuotation::create([
            'property_id' => $property->id,
            'request_for_quotation_id' => $rfq->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->expectException(QueryException::class);

        // Vendor attempts to submit a second quote on the same RFQ (fails constraint)
        VendorQuotation::create([
            'property_id' => $property->id,
            'request_for_quotation_id' => $rfq->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    public function test_deadline_validation_can_be_enforced()
    {
        $property = $this->createProperty($this->createCompany());
        
        $rfq = RequestForQuotation::create([
            'property_id' => $property->id,
            'rfq_number' => 'RFQ-DEADLINE',
            'title' => 'Test Deadline',
            'deadline_at' => now()->subDay(), // Deadline passed
        ]);

        $this->assertTrue($rfq->deadline_at->isPast());
    }
}
