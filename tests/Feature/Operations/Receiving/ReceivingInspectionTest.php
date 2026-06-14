<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;

class ReceivingInspectionTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_can_log_inspection()
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
            'grn_number' => 'GRN-INSP-TEST',
        ]);

        $line = $doc->lines()->create([
            'description' => 'F&B Item',
            'received_quantity' => 10,
            'unit_cost' => 5,
            'line_total' => 50,
        ]);

        $insp = $line->inspections()->create([
            'inspection_result' => 'PASS',
            'temperature' => -18.0,
            'visual_quality_score' => 'Perfect',
        ]);

        $this->assertDatabaseHas('receiving_inspections', [
            'id' => $insp->id,
            'receiving_line_id' => $line->id,
            'inspection_result' => 'PASS',
            'temperature' => -18.0,
        ]);
    }
}
