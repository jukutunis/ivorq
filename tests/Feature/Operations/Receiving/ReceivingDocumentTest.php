<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;

class ReceivingDocumentTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    protected Property $property;
    protected Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
        $this->property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        
        $vendor = Vendor::where('property_id', $this->property->id)->first();
        if (!$vendor) {
            $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $this->property->id, 'name' => 'Cat', 'category_code' => 'C']);
            $vendor = Vendor::create(['property_id' => $this->property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        }
        $this->vendor = $vendor;
    }

    public function test_can_create_receiving_document()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-2026-TEST',
            'vendor_delivery_no' => 'DO-001',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('receiving_documents', [
            'id' => $doc->id,
            'grn_number' => 'GRN-2026-TEST',
            'vendor_delivery_no' => 'DO-001',
        ]);
        
        $this->assertTrue($doc->vendor->is($this->vendor));
    }

    public function test_can_soft_delete_receiving_document()
    {
        $doc = ReceivingDocument::create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'grn_number' => 'GRN-2026-TEST2',
        ]);
        
        $doc->delete();
        $this->assertSoftDeleted('receiving_documents', ['id' => $doc->id]);
    }
}
