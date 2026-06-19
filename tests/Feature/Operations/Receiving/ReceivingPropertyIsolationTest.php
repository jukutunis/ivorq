<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;

class ReceivingPropertyIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    use RefreshDatabase;
    protected $seed = true;

    public function test_receiving_documents_are_isolated_by_property()
    {
        $propertyA = Property::first();
        $propertyB = Property::skip(1)->first();

        app(CurrentPropertyService::class)->setPropertyId($propertyA->id);
        $vendorA = Vendor::where('property_id', $propertyA->id)->first();
        if (!$vendorA) {
            $categoryA = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $propertyA->id, 'name' => 'Cat1', 'category_code' => 'C1']);
            $vendorA = Vendor::create([
                'property_id' => $propertyA->id,
                'vendor_category_id' => $categoryA->id,
                'name' => 'Test Vendor A',
                'vendor_code' => 'TV-A',
                'status' => 'active',
            ]);
        }
        ReceivingDocument::create([
            'property_id' => $propertyA->id,
            'vendor_id' => $vendorA->id,
            'grn_number' => 'GRN-PROP-A',
        ]);

        app(CurrentPropertyService::class)->setPropertyId($propertyB->id);
        $vendorB = Vendor::where('property_id', $propertyB->id)->first();
        if (!$vendorB) {
            $categoryB = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $propertyB->id, 'name' => 'Cat2', 'category_code' => 'C2']);
            $vendorB = Vendor::create([
                'property_id' => $propertyB->id,
                'vendor_category_id' => $categoryB->id,
                'name' => 'Test Vendor B',
                'vendor_code' => 'TV-B',
                'status' => 'active',
            ]);
        }

        ReceivingDocument::create([
            'property_id' => $propertyB->id,
            'vendor_id' => $vendorB->id,
            'grn_number' => 'GRN-PROP-B',
        ]);

        $this->assertEquals(1, ReceivingDocument::count());
        $this->assertEquals('GRN-PROP-B', ReceivingDocument::first()->grn_number);

        app(CurrentPropertyService::class)->setPropertyId($propertyA->id);
        $this->assertEquals(1, ReceivingDocument::count());
        $this->assertEquals('GRN-PROP-A', ReceivingDocument::first()->grn_number);
    }
}
