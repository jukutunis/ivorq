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
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

// Dummy Approvable for testing
class DummyPurchaseOrder extends Model implements ApprovableContract
{
    use HasUlid, BelongsToProperty;

    protected $guarded = [];
    public $timestamps = false;
    protected $table = 'dummy_purchase_orders'; // Mock table or just mock the instance

    public function getApprovableType(): string { return self::class; }
    public function getApprovableId(): string { return $this->id; }
    public function getPropertyId(): string { return $this->property_id; }
    public function getDepartmentId(): ?string { return null; }
    public function getApprovalAmount(): float { return 1000.00; }
    public function markAsApproved(): void { $this->status = 'Approved'; $this->save(); }
    public function markAsRejected(?string $reason = null): void { $this->status = 'Rejected'; $this->save(); }
}

class ApprovalEngineTest extends TestCase
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

    public function test_approval_engine_complete_flow_with_snapshot()
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

        ApprovalStepAssignee::create([
            'step_id' => $step->id,
            'assignee_type' => 'USER',
            'user_id' => $approver->id,
        ]);

        $dummyPO = DummyPurchaseOrder::create([
            'property_id' => $property->id,
        ]);

        $engineService = app(ApprovalEngineService::class);

        // 1. Submit for approval
        $request = $engineService->submitForApproval($dummyPO, $requester->id);

        $this->assertEquals('Pending', $request->status);
        $this->assertNotNull($request->workflow_snapshot);
        $this->assertNotNull($request->step_snapshot);

        // 2. Approve
        $engineService->approve($request, $approver->id, 'Looks good');

        $request->refresh();
        $this->assertEquals('Approved', $request->status);
        
        $dummyPO->refresh();
        $this->assertEquals('Approved', $dummyPO->status);
    }
}
