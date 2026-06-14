<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;

class PurchasingPropertyIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_purchase_requests_are_isolated_by_property()
    {
        $propertyA = $this->createProperty($this->createCompany());
        $propertyB = $this->createProperty($this->createCompany());
        $departmentA = Department::create(['property_id' => $propertyA->id, 'name' => 'IT', 'code' => 'ITA']);
        $departmentB = Department::create(['property_id' => $propertyB->id, 'name' => 'IT', 'code' => 'ITB']);
        $userA = $this->createUser($propertyA);
        $userB = $this->createUser($propertyB);

        PurchaseRequest::create([
            'property_id' => $propertyA->id,
            'request_no' => 'PR-A-01',
            'department_id' => $departmentA->id,
            'requester_id' => $userA->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);

        PurchaseRequest::create([
            'property_id' => $propertyB->id,
            'request_no' => 'PR-B-01',
            'department_id' => $departmentB->id,
            'requester_id' => $userB->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);

        // Mock app property context to A
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyA->id);

        $prsInA = PurchaseRequest::all();
        $this->assertCount(1, $prsInA);
        $this->assertEquals('PR-A-01', $prsInA->first()->request_no);

        // Mock app property context to B
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($propertyB->id);

        $prsInB = PurchaseRequest::all();
        $this->assertCount(1, $prsInB);
        $this->assertEquals('PR-B-01', $prsInB->first()->request_no);
    }
}
