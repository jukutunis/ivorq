<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;

class ReceivingLineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    use RefreshDatabase;
    protected $seed = true;

    public function test_can_add_lines_to_document()
    {
        $property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $vendor = Vendor::where('property_id', $property->id)->first();
        if (!$vendor) {
            $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'Cat', 'category_code' => 'C']);
            $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        }

        $doc = ReceivingDocument::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'GRN-LINE-TEST',
        ]);

        $line = $doc->lines()->create([
            'description' => 'Test Item',
            'received_quantity' => 5,
            'unit_cost' => 10,
            'line_total' => 50,
            'destination_location_id' => '01HXXXXXXXAXXXXXXXBXXXXXXX',
        ]);

        $this->assertDatabaseHas('receiving_lines', [
            'id' => $line->id,
            'receiving_document_id' => $doc->id,
            'description' => 'Test Item',
            'destination_location_id' => '01HXXXXXXXAXXXXXXXBXXXXXXX',
        ]);
        
        $this->assertCount(1, $doc->lines);
    }
}
