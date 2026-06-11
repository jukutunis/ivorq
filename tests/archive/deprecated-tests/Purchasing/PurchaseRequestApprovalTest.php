<?php

namespace Tests\Feature\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class PurchaseRequestApprovalTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected User $user;
    protected $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPurchasingPermissions();
        
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->user = $this->createPropertyAdmin($this->property);
    }

    public function test_can_submit_purchase_request()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Draft->value,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('purchasing.purchase-requests.submit', $pr->id));

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PurchaseRequestStatusEnum::Submitted->value);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => PurchaseRequestStatusEnum::Submitted->value,
        ]);
    }

    public function test_can_approve_purchase_request()
    {
        // Setup Workflow
        $workflow = ApprovalWorkflow::factory()->create([
            'property_id' => $this->property->id,
            'module' => 'purchasing',
        ]);

        ApprovalStep::factory()->create([
            'workflow_id' => $workflow->id,
            'sequence_no' => 1,
            'approval_limit' => 5000,
        ]);

        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Submitted->value,
            'estimated_total' => 1000, // Fully approved by step 1
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('purchasing.purchase-requests.approve', $pr->id), [
                'remarks' => 'Approved by GM'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PurchaseRequestStatusEnum::Approved->value);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $this->assertDatabaseHas('approval_snapshots', [
            'reference_id' => $pr->id,
            'action' => 'Approved',
            'remarks' => 'Approved by GM',
        ]);
    }

    public function test_can_reject_purchase_request()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Submitted->value,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('purchasing.purchase-requests.reject', $pr->id), [
                'remarks' => 'Not needed'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PurchaseRequestStatusEnum::Rejected->value);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => PurchaseRequestStatusEnum::Rejected->value,
        ]);

        $this->assertDatabaseHas('approval_snapshots', [
            'reference_id' => $pr->id,
            'action' => 'Rejected',
            'remarks' => 'Not needed',
        ]);
    }

    public function test_cannot_approve_draft_purchase_request()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Draft->value,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('purchasing.purchase-requests.approve', $pr->id));

        $response->assertStatus(400) // BusinessLogicException converts to 400
                 ->assertJsonPath('message', 'Only Submitted Purchase Requests can be approved.');
    }
}
