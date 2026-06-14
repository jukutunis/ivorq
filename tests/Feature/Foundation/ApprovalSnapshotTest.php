<?php

namespace Modules\Foundation\Approval\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ApprovalSnapshotTest extends TestCase
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

    public function test_workflow_changes_do_not_affect_snapshot()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $requester = $this->createUser($property);
        $approver = $this->createUser($property);

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

        $dummyPO = DummyPurchaseOrder::create(['property_id' => $property->id]);
        $engineService = app(ApprovalEngineService::class);
        $request = $engineService->submitForApproval($dummyPO, $requester->id);

        // Now mutate the workflow
        $workflow->name = 'Mutated Workflow';
        $workflow->save();
        $step->name = 'Mutated Step';
        $step->save();

        $this->assertNotEquals('Mutated Workflow', $request->workflow_snapshot['name']);
        $this->assertEquals('PO Approval', $request->workflow_snapshot['name']);

        $stepSnap = collect($request->step_snapshot)->firstWhere('id', $step->id);
        $this->assertEquals('Review', $stepSnap['name']);
    }
}
