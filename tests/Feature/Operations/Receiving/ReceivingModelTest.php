<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Receiving\Models\ReceivingLine;
use Modules\Operations\Receiving\Models\ReceivingDiscrepancy;
use Modules\Operations\Receiving\Models\ReceivingInspection;
use Modules\Operations\Receiving\Models\ReceivingAttachment;
use Modules\Operations\Receiving\Models\ReceivingComment;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;

class ReceivingModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    use RefreshDatabase;
    protected $seed = true;

    public function test_receiving_document_model_has_traits_and_scopes()
    {
        $traits = class_uses_recursive(ReceivingDocument::class);
        $this->assertArrayHasKey('Shared\Traits\HasUlid', $traits);
        $this->assertArrayHasKey('Shared\Traits\BelongsToProperty', $traits);
        $this->assertArrayHasKey('Shared\Traits\HasAuditColumns', $traits);
        $this->assertArrayHasKey('Spatie\Activitylog\Models\Concerns\LogsActivity', $traits);
        $this->assertArrayHasKey('Illuminate\Database\Eloquent\SoftDeletes', $traits);

        $property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($property->id);

        $vendor = Vendor::where('property_id', $property->id)->first();
        if (!$vendor) {
            $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'Cat', 'category_code' => 'C']);
            $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        }

        $document = ReceivingDocument::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'GRN-TEST-001',
        ]);

        $this->assertNotNull($document->id);
        $this->assertEquals(26, strlen($document->id));
        $this->assertEquals($property->id, $document->property_id);
    }

    public function test_receiving_line_model_has_traits()
    {
        $traits = class_uses_recursive(ReceivingLine::class);
        $this->assertArrayHasKey('Shared\Traits\HasUlid', $traits);
        $this->assertArrayHasKey('Shared\Traits\HasAuditColumns', $traits);
        $this->assertArrayHasKey('Spatie\Activitylog\Models\Concerns\LogsActivity', $traits);
    }

    public function test_receiving_discrepancy_model_has_traits()
    {
        $traits = class_uses_recursive(ReceivingDiscrepancy::class);
        $this->assertArrayHasKey('Shared\Traits\HasUlid', $traits);
        $this->assertArrayHasKey('Shared\Traits\HasAuditColumns', $traits);
    }

    public function test_receiving_inspection_model_has_traits()
    {
        $traits = class_uses_recursive(ReceivingInspection::class);
        $this->assertArrayHasKey('Shared\Traits\HasUlid', $traits);
        $this->assertArrayHasKey('Shared\Traits\HasAuditColumns', $traits);
    }

    public function test_receiving_attachment_and_comment_models_have_traits()
    {
        $traitsA = class_uses_recursive(ReceivingAttachment::class);
        $this->assertArrayHasKey('Shared\Traits\HasUlid', $traitsA);
        
        $traitsC = class_uses_recursive(ReceivingComment::class);
        $this->assertArrayHasKey('Shared\Traits\HasUlid', $traitsC);
    }
}
