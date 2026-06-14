<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\User\Models\User;
use Illuminate\Support\Facades\Event;

class ReceivingNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_approval_approved_creates_notification()
    {
        $property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $vendor = Vendor::where('property_id', $property->id)->first();
        if (!$vendor) {
            $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'Cat', 'category_code' => 'C']);
            $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        }
        $user = User::first();

        $this->actingAs($user);
        $doc = ReceivingDocument::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'GRN-NOTIFY-TEST',
        ]);

        $workflow = ApprovalWorkflow::create([
            'property_id' => $property->id,
            'name' => 'Receiving Approval',
            'approvable_type' => ReceivingDocument::class,
            'is_active' => true,
        ]);

        $request = ApprovalRequest::create([
            'property_id' => $property->id,
            'workflow_id' => $workflow->id,
            'requester_id' => $user->id,
            'approvable_type' => get_class($doc),
            'approvable_id' => $doc->id,
            'status' => 'approved',
        ]);

        Event::dispatch(new ApprovalApproved($request));

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'type' => 'receiving.approved',
        ]);
    }
}
