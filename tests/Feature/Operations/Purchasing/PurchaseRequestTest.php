<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Foundation\Department\Models\Department;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Illuminate\Database\QueryException;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_pr_lifecycle_and_approval_governance()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $creator = $this->createUser($property);
        $approver = $this->createUser($property); // In a real app we would assign roles, but here we just test fields

        $pr = PurchaseRequest::create([
            'property_id' => $property->id,
            'request_no' => 'PR-001',
            'department_id' => $department->id,
            'requester_id' => $creator->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
            'status' => PurchaseRequestStatusEnum::Draft->value,
        ]);

        $this->assertEquals(PurchaseRequestStatusEnum::Draft, $pr->status);

        // Transition to PENDING_REVIEW
        $pr->update(['status' => PurchaseRequestStatusEnum::PendingReview->value]);
        $this->assertEquals(PurchaseRequestStatusEnum::PendingReview, $pr->status);

        // Authenticate as approver
        $this->actingAs($approver);

        // Transition to APPROVED
        $pr->markAsApproved();
        $pr->refresh();

        $this->assertEquals(PurchaseRequestStatusEnum::Approved, $pr->status);
        $this->assertEquals($approver->id, $pr->approved_by);
        $this->assertNotNull($pr->approved_at);
        $this->assertNull($pr->rejected_by);

        // Transition to REJECTED
        $pr->markAsRejected('Budget exceeded');
        $pr->refresh();

        $this->assertEquals(PurchaseRequestStatusEnum::Rejected, $pr->status);
        $this->assertEquals($approver->id, $pr->rejected_by);
        $this->assertNotNull($pr->rejected_at);
        $this->assertEquals('Budget exceeded', $pr->rejection_reason);
    }

    public function test_pr_property_isolation_and_duplicate_prevention()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        $departmentA = Department::create(['property_id' => $propertyA->id, 'name' => 'IT', 'code' => 'IT-A']);
        $departmentB = Department::create(['property_id' => $propertyB->id, 'name' => 'IT', 'code' => 'IT-B']);

        $userA = $this->createUser($propertyA);
        $userB = $this->createUser($propertyB);

        // Create PR in Property A
        PurchaseRequest::create([
            'property_id' => $propertyA->id,
            'request_no' => 'PR-ISO',
            'department_id' => $departmentA->id,
            'requester_id' => $userA->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 100,
        ]);

        // Same request_no in Property B should succeed (Isolation)
        $prB = PurchaseRequest::create([
            'property_id' => $propertyB->id,
            'request_no' => 'PR-ISO',
            'department_id' => $departmentB->id,
            'requester_id' => $userB->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 200,
        ]);

        $this->assertNotNull($prB->id);

        $this->expectException(QueryException::class);

        // Same request_no in Property A should fail (Duplicate Prevention)
        PurchaseRequest::create([
            'property_id' => $propertyA->id,
            'request_no' => 'PR-ISO',
            'department_id' => $departmentA->id,
            'requester_id' => $userA->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 300,
        ]);
    }
}
