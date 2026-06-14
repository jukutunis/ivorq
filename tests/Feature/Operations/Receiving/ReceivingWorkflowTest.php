<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Receiving\Services\ReceivingService;
use Modules\Operations\Receiving\Services\ReceivingValidationService;
use Modules\Operations\Receiving\Repositories\ReceivingRepository;
use Modules\Operations\Receiving\Repositories\ReceivingLineRepository;
use Modules\Operations\Receiving\Enums\ReceivingDocumentStatusEnum;

class ReceivingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_can_create_draft_and_submit()
    {
        $property = Property::first();
        $user = \Modules\Foundation\User\Models\User::first();
        $this->actingAs($user);
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $vendor = Vendor::where('property_id', $property->id)->first();
        if (!$vendor) {
            $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'Cat', 'category_code' => 'C']);
            $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        }

        // Setup Approval Workflow so submitForApproval succeeds
        $workflow = \Modules\Foundation\Approval\Models\ApprovalWorkflow::create([
            'property_id' => $property->id,
            'name' => 'Receiving Approval',
            'approvable_type' => ReceivingDocument::class,
            'is_active' => true,
        ]);
        \Modules\Foundation\Approval\Models\ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'name' => 'First Step',
            'sequence' => 1,
            'required_approvals' => 1,
        ]);

        $service = app(ReceivingService::class);

        $document = $service->createDraft([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'lines' => [
                [
                    'description' => 'Test',
                    'received_quantity' => 10,
                    'unit_cost' => 5,
                    'line_total' => 50,
                ]
            ]
        ]);

        $this->assertEquals(ReceivingDocumentStatusEnum::Draft->value, $document->status->value);
        $this->assertCount(1, $document->lines);

        $service->submit($document->id);

        $this->assertEquals(ReceivingDocumentStatusEnum::Submitted->value, $document->fresh()->status->value);
    }
}
