<?php

namespace Modules\Foundation\Approval\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Approval\Services\ApprovalEscalationService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;
use Carbon\Carbon;

class ApprovalEscalationTest extends TestCase
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

    public function test_escalation_triggers_on_timeout()
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
            'timeout_hours' => 24,
        ]);

        ApprovalStepAssignee::create([
            'step_id' => $step->id,
            'assignee_type' => 'USER',
            'user_id' => $approver->id,
        ]);

        $dummyPO = DummyPurchaseOrder::create(['property_id' => $property->id]);
        $engineService = app(ApprovalEngineService::class);
        $request = $engineService->submitForApproval($dummyPO, $requester->id);

        $this->assertEquals('Pending', $request->status);

        // Time travel 25 hours
        Carbon::setTestNow(Carbon::now()->addHours(25));

        $escalationService = app(ApprovalEscalationService::class);
        $escalationService->checkEscalations();

        $request->refresh();
        $this->assertEquals('Escalated', $request->status);

        // Time travel 49 hours from update
        Carbon::setTestNow(Carbon::now()->addHours(49));
        $escalationService->checkEscalations();

        $request->refresh();
        $this->assertEquals('Expired', $request->status);
    }
}
