<?php

namespace Modules\Foundation\Approval\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Models\ApprovalDelegate;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;
use Carbon\Carbon;

class ApprovalDelegateTest extends TestCase
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

    public function test_delegation_routes_to_delegate()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $requester = $this->createUser($property);
        $approver = $this->createUser($property);
        $delegate = $this->createUser($property);

        ApprovalDelegate::create([
            'property_id' => $property->id,
            'delegator_id' => $approver->id,
            'delegate_id' => $delegate->id,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->addDay(),
            'is_active' => true,
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
            'assignee_type' => 'USER',
            'user_id' => $approver->id,
        ]);

        $dummyPO = DummyPurchaseOrder::create(['property_id' => $property->id]);
        $engineService = app(ApprovalEngineService::class);
        $request = $engineService->submitForApproval($dummyPO, $requester->id);

        // Delegate approves
        $engineService->approve($request, $delegate->id, 'Approved as delegate');

        $request->refresh();
        $this->assertEquals('Approved', $request->status);
    }
}
