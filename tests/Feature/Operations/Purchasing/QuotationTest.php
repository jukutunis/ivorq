<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\Quotation;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;

class QuotationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_create_quotation_with_lines()
    {
        $property = $this->createProperty($this->createCompany());
        $user = $this->createUser($property);
        $rfq = RFQ::create([
            'property_id' => $property->id,
            'rfq_number' => 'RFQ-Q-1',
            'title' => 'Test RFQ',
            'created_by' => $user->id,
        ]);

        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST',
            'name' => 'Test',
        ]);
        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-03',
            'name' => 'Vendor 3',
        ]);

        $quotation = Quotation::create([
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'total_amount' => 1000,
        ]);

        $line = $quotation->lines()->create([
            'item_name' => 'Service',
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
        ]);

        $this->assertNotNull($quotation->id);
        $this->assertEquals(1000, $quotation->total_amount);
        $this->assertEquals($rfq->id, $quotation->rfq_id);
        $this->assertEquals($vendor->id, $quotation->vendor_id);
        
        $this->assertCount(1, $quotation->lines);
        $this->assertEquals('Service', $quotation->lines->first()->description);
    }
}
