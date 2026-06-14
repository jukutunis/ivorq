<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;

class RFQTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_create_rfq()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-RFQ-1',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);

        $rfq = RFQ::create([
            'property_id' => $property->id,
            'purchase_request_id' => $pr->id,
            'rfq_number' => 'RFQ-001',
            'title' => 'Test RFQ',
            'deadline_at' => now()->addDays(5),
        ]);

        $this->assertNotNull($rfq->id);
        $this->assertEquals('RFQ-001', $rfq->rfq_number);
        $this->assertEquals($pr->id, $rfq->purchase_request_id);
    }

    public function test_rfq_belongs_to_pr()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id, 
            'request_no' => 'PR-RFQ-2',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);

        $rfq = RFQ::create([
            'property_id' => $property->id,
            'purchase_request_id' => $pr->id,
            'rfq_number' => 'RFQ-002',
            'title' => 'Test RFQ 2',
            'deadline_at' => now()->addDays(5),
        ]);

        $this->assertEquals($pr->id, $rfq->purchaseRequest->id);
    }
}
