<?php

namespace Modules\Foundation\Approval\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Models\ApprovalMatrixRule;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ApprovalMatrixTest extends TestCase
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

    public function test_matrix_rule_routes_based_on_amount()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $requester = $this->createUser($property);
        $approver = $this->createUser($property);

        // Matrix rule requires amount > 500 for this user
        ApprovalMatrixRule::create([
            'property_id' => $property->id,
            'module' => 'Purchasing',
            'document_type' => 'DummyPurchaseOrder',
            'user_id' => $approver->id,
            'assignee_type' => 'USER',
            'min_amount' => 500,
            'max_amount' => 5000,
        ]);

        $workflow = ApprovalWorkflow::create([
            'property_id' => $property->id,
            'name' => 'PO Approval',
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
            'assignee_type' => 'MATRIX_RULE',
        ]);

        $dummyPO = DummyPurchaseOrder::create(['property_id' => $property->id]); // Returns 1000 by default in getApprovalAmount
        $engineService = app(ApprovalEngineService::class);
        $request = $engineService->submitForApproval($dummyPO, $requester->id);

        // Approver should be resolved because 1000 is between 500 and 5000
        $engineService->approve($request, $approver->id, 'Approving matrix');

        $request->refresh();
        $this->assertEquals('Approved', $request->status);
    }
}
