<?php

namespace Modules\Foundation\Approval\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Models\ApprovalRequest;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ApprovalIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Schema::create('dummy_purchase_orders', function ($table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('status')->default('Draft');
        });
    }

    public function test_cannot_approve_request_from_another_property()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        $requesterA = $this->createUser($propertyA);
        $approverB = $this->createUser($propertyB); // Approver is from Property B

        $workflow = ApprovalWorkflow::create([
            'property_id' => $propertyA->id,
            'name' => 'PO Approval A',
            'approvable_type' => DummyPurchaseOrder::class,
            'is_active' => true,
        ]);

        $step = ApprovalStep::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Review',
            'required_approvals' => 1,
        ]);

        ApprovalStepAssignee::create([
            'step_id' => $step->id,
            'assignee_type' => 'USER',
            'user_id' => $approverB->id,
        ]);

        $dummyPO = DummyPurchaseOrder::create(['property_id' => $propertyA->id]);
        $engineService = app(ApprovalEngineService::class);
        $request = $engineService->submitForApproval($dummyPO, $requesterA->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User does not belong to the requested property');

        // This should throw an exception because approverB is not in Property A
        $engineService->approve($request, $approverB->id, 'Try to approve across properties');
    }
}
