<?php

namespace Tests\Feature\Operations\Purchasing;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;

class PurchasingApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_purchase_request_full_approval_workflow()
    {
        $property = $this->createProperty($this->createCompany());
        $user = $this->createUser($property);
        

        $department = \Modules\Foundation\Department\Models\Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-FLOW-1', 
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
            'status' => PurchaseRequestStatusEnum::Draft->value
        ]);

        $workflow = ApprovalWorkflow::create([
            'property_id' => $property->id,
            'approvable_type' => PurchaseRequest::class,
            'name' => 'PR Approval',
            'is_active' => true,
        ]);

        ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Manager Approval',
            'required_approvals' => 1,
        ]);

        $service = app(ApprovalEngineService::class);
        $approvalRequest = $service->submitForApproval($pr, $user->id);

        $this->assertEquals('Pending', $approvalRequest->status);
        $this->assertEquals(1, $approvalRequest->currentStep->sequence);

        $service->approve($approvalRequest, $user->id, 'Approved by manager');

        $approvalRequest->refresh();
        $pr->refresh();

        $this->assertEquals('Approved', $approvalRequest->status);
        $this->assertEquals(PurchaseRequestStatusEnum::Approved->value, $pr->status->value);
    }
}
