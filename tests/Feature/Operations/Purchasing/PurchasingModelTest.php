<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorContact;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseRequestLine;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Operations\Purchasing\Models\Quotation;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Foundation\Property\Models\Property;

class PurchasingModelTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_vendor_category_can_be_created()
    {
        $property = $this->createProperty($this->createCompany());
        
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'name' => 'IT Equipment',
            'category_code' => 'IT',
        ]);

        $this->assertNotNull($category->id);
        $this->assertEquals('IT', $category->category_code);
    }

    public function test_vendor_can_be_created_with_global_and_local_scope()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'name' => 'IT Equipment',
            'category_code' => 'IT',
        ]);

        $globalVendor = Vendor::withoutEvents(function () use ($category) {
            $vendor = new Vendor();
            $vendor->id = (string) str(\Illuminate\Support\Str::ulid());
            $vendor->name = 'Dell Global';
            $vendor->vendor_code = 'DELL';
            $vendor->vendor_category_id = $category->id;
            $vendor->save();
            return $vendor;
        });

        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($property->id);

        $localVendor = Vendor::create([
            'property_id' => $property->id,
            'name' => 'Local PC Shop',
            'vendor_code' => 'LOCAL-PC',
            'vendor_category_id' => $category->id,
        ]);

        $this->assertNull($globalVendor->property_id);
        $this->assertEquals($property->id, $localVendor->property_id);
    }

    public function test_rfq_and_quotations_can_be_linked()
    {
        $property = $this->createProperty($this->createCompany());
        $user = $this->createUser($property);
        $rfq = RFQ::create([
            'property_id' => $property->id,
            'rfq_number' => 'RFQ-001',
            'title' => 'Laptops',
            'created_by' => $user->id,
        ]);

        $category = VendorCategory::create([
            'property_id' => $property->id,
            'name' => 'IT Equipment',
            'category_code' => 'IT',
        ]);

        $vendor = Vendor::create([
            'property_id' => $property->id,
            'name' => 'Local PC Shop',
            'vendor_code' => 'LOCAL-PC',
            'vendor_category_id' => $category->id,
        ]);

        $quotation = Quotation::create([
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'total_amount' => 1000,
        ]);

        $this->assertEquals($rfq->id, $quotation->rfq->id);
        $this->assertEquals($vendor->id, $quotation->vendor->id);
    }
}
