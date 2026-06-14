<?php

namespace Tests\Feature\Operations\Receiving;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Modules\Operations\Purchasing\Models\Vendor;
use Shared\Services\CurrentPropertyService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\User\Models\User;
use Illuminate\Support\Facades\Event;

class ReceivingTaskIntegrationTest extends TestCase
{
    use RefreshDatabase;
    protected $seed = true;

    public function test_approval_requested_creates_task_for_assignee()
    {
        $property = Property::first();
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        $vendor = Vendor::where('property_id', $property->id)->first();
        if (!$vendor) {
            $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(['property_id' => $property->id, 'name' => 'Cat', 'category_code' => 'C']);
            $vendor = Vendor::create(['property_id' => $property->id, 'vendor_category_id' => $category->id, 'name' => 'Test', 'vendor_code' => 'T', 'status' => 'active']);
        }
        $user = User::first();

        $doc = ReceivingDocument::create([
            'property_id' => $property->id,
            'vendor_id' => $vendor->id,
            'grn_number' => 'GRN-TASK-TEST',
        ]);

        $workflow = ApprovalWorkflow::create([
            'property_id' => $property->id,
            'name' => 'Receiving Approval',
            'approvable_type' => 'receiving_document',
            'is_active' => true,
        ]);

        $request = ApprovalRequest::create([
            'property_id' => $property->id,
            'workflow_id' => $workflow->id,
            'requester_id' => $user->id,
            'approvable_type' => get_class($doc),
            'approvable_id' => $doc->id,
            'status' => 'pending',
        ]);

        $step = ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Step 1',
            'required_approvals' => 1,
        ]);

        ApprovalStepAssignee::create([
            'step_id' => $step->id,
            'assignee_type' => 'user',
            'user_id' => $user->id,
        ]);

        $request->update(['current_step_id' => $step->id]);

        Event::dispatch(new ApprovalRequested($request));

        $this->assertDatabaseHas('tasks', [
            'taskable_type' => get_class($doc),
            'taskable_id' => $doc->id,
            'status' => 'open',
        ]);
        
        $task = \Modules\Foundation\Task\Models\Task::where('taskable_id', $doc->id)->first();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'assignee_type' => 'user',
            'assignee_id' => $user->id,
        ]);
    }
}
