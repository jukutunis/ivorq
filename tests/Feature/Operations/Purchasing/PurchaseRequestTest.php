<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\PurchaseRequestLine;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryUnit;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_create_purchase_request_with_lines()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'IT', 'code' => 'IT']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id,
            'request_no' => 'PR-001',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 1000,
        ]);

        $unit = InventoryUnit::create(['property_id' => $property->id, 'code' => 'PCS', 'name' => 'Pieces']);

        $line = $pr->lines()->create([
            'description' => 'Laptop',
            'quantity' => 1,
            'estimated_unit_cost' => 1000,
            'unit_id' => $unit->id, 
            'estimated_total_cost' => 1000,
        ]);

        $this->assertNotNull($pr->id);
        $this->assertEquals('PR-001', $pr->request_no);
        $this->assertEquals(1000, $pr->estimated_total);
        
        $this->assertCount(1, $pr->lines);
        $this->assertEquals('Laptop', $pr->lines->first()->description);
        $this->assertEquals(1000, $pr->lines->first()->estimated_total_cost);
    }

    public function test_purchase_request_is_soft_deletable()
    {
        $property = $this->createProperty($this->createCompany());
        $department = Department::create(['property_id' => $property->id, 'name' => 'HR', 'code' => 'HR']);
        $user = $this->createUser($property);

        $pr = PurchaseRequest::create([
            'property_id' => $property->id,
            'request_no' => 'PR-002',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->addDays(7),
            'estimated_total' => 500,
        ]);

        $pr->delete();

        $this->assertSoftDeleted($pr);
    }
}
