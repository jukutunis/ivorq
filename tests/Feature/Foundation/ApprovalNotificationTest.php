<?php

namespace Modules\Foundation\Approval\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Services\ApprovalEngineService;
use Modules\Foundation\Notification\Models\NotificationPreference;
use Modules\Foundation\Notification\Models\AppNotification;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class ApprovalNotificationTest extends TestCase
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

    public function test_muted_preferences_stop_notifications()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $requester = $this->createUser($property);
        $approver = $this->createUser($property);

        NotificationPreference::create([
            'property_id' => $property->id,
            'user_id' => $approver->id,
            'notification_type' => 'approvals',
            'in_app_enabled' => true,
            'is_muted' => true,
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
        $engineService->submitForApproval($dummyPO, $requester->id);

        $notificationsCount = AppNotification::where('user_id', $approver->id)->count();
        $this->assertEquals(0, $notificationsCount);
    }
}
