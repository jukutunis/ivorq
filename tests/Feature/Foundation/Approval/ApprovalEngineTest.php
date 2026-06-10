<?php

namespace Tests\Feature\Foundation\Approval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Enums\ApprovalActionEnum;
use Modules\Foundation\Approval\Models\ApprovalSnapshot;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected ApprovalEngineService $engine;
    protected $property;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->engine = app(ApprovalEngineService::class);
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
    }

    public function test_submit_document_returns_first_step()
    {
        $workflow = ApprovalWorkflow::factory()->create([
            'property_id' => $this->property->id,
            'module' => 'purchasing',
        ]);

        $step1 = ApprovalStep::factory()->create([
            'workflow_id' => $workflow->id,
            'sequence_no' => 1,
            'approval_limit' => 5000,
        ]);

        $pr = $this->createPurchaseRequest($this->property, [
            'estimated_total' => 1000,
        ]);

        $nextStep = $this->engine->submitDocument($pr, 'purchasing');

        $this->assertNotNull($nextStep);
        $this->assertEquals($step1->id, $nextStep->id);
    }

    public function test_submit_document_returns_null_if_no_workflow()
    {
        $pr = $this->createPurchaseRequest($this->property);

        $nextStep = $this->engine->submitDocument($pr, 'purchasing');

        $this->assertNull($nextStep);
    }

    public function test_approve_document_creates_snapshot_and_returns_next_step()
    {
        $workflow = ApprovalWorkflow::factory()->create([
            'property_id' => $this->property->id,
            'module' => 'purchasing',
        ]);

        $step1 = ApprovalStep::factory()->create([
            'workflow_id' => $workflow->id,
            'sequence_no' => 1,
            'approval_limit' => 1000,
        ]);

        $step2 = ApprovalStep::factory()->create([
            'workflow_id' => $workflow->id,
            'sequence_no' => 2,
            'approval_limit' => 10000,
        ]);

        $pr = $this->createPurchaseRequest($this->property, [
            'estimated_total' => 5000,
        ]);

        $user = $this->createUser($this->property);
        $nextStep = $this->engine->approve($pr, 'purchasing', 5000, $user->id, 'John Doe', 'Manager', 'Looks good');

        $this->assertNotNull($nextStep);
        $this->assertEquals($step2->id, $nextStep->id);

        $this->assertDatabaseHas('approval_snapshots', [
            'reference_type' => get_class($pr),
            'reference_id' => $pr->id,
            'workflow_id' => $workflow->id,
            'sequence_no' => 1,
            'action' => ApprovalActionEnum::Approved->value,
            'remarks' => 'Looks good',
        ]);
    }

    public function test_approve_document_returns_null_when_fully_approved_by_limit()
    {
        $workflow = ApprovalWorkflow::factory()->create([
            'property_id' => $this->property->id,
            'module' => 'purchasing',
        ]);

        $step1 = ApprovalStep::factory()->create([
            'workflow_id' => $workflow->id,
            'sequence_no' => 1,
            'approval_limit' => 5000,
        ]);

        $step2 = ApprovalStep::factory()->create([
            'workflow_id' => $workflow->id,
            'sequence_no' => 2,
            'approval_limit' => 10000,
        ]);

        $pr = $this->createPurchaseRequest($this->property, [
            'estimated_total' => 1000, // less than step 1 limit
        ]);

        $user = $this->createUser($this->property);
        $nextStep = $this->engine->approve($pr, 'purchasing', 1000, $user->id, 'John Doe', 'Manager');

        // Should return null because step 1 limit covers it
        $this->assertNull($nextStep);
    }

    public function test_reject_document_creates_rejection_snapshot()
    {
        $workflow = ApprovalWorkflow::factory()->create([
            'property_id' => $this->property->id,
            'module' => 'purchasing',
        ]);

        $pr = $this->createPurchaseRequest($this->property);

        $user = $this->createUser($this->property);
        $this->engine->reject($pr, 'purchasing', $user->id, 'John Doe', 'Manager', 'Over budget');

        $this->assertDatabaseHas('approval_snapshots', [
            'reference_type' => get_class($pr),
            'reference_id' => $pr->id,
            'workflow_id' => $workflow->id,
            'action' => ApprovalActionEnum::Rejected->value,
            'remarks' => 'Over budget',
        ]);
    }
}
