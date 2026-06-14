<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Foundation\Approval\Contracts\ApprovableContract;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;

class PurchasingApprovalIntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_purchase_request_implements_approvable_contract()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-APP-1', 
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 5000
        ]);

        $this->assertInstanceOf(ApprovableContract::class, $pr);
        $this->assertEquals(PurchaseRequest::class, $pr->getApprovableType());
        $this->assertEquals($pr->id, $pr->getApprovableId());
        $this->assertEquals($property->id, $pr->getPropertyId());
        $this->assertEquals(5000, $pr->getApprovalAmount());
    }

    public function test_purchase_request_mark_as_approved()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-APP-2', 
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 5000,
            'status' => 'Submitted'
        ]);

        $pr->markAsApproved();
        $pr->refresh();

        $this->assertEquals('Approved', $pr->status->value);
    }
}
