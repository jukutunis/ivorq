<?php

namespace Tests\Feature\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class PurchaseRequestModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPurchasingPermissions();
    }

    public function test_can_create_purchase_request_with_lines()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $department = $this->createDepartment($property);
        $unit = InventoryUnit::create(['property_id' => $property->id, 'unit_code' => 'PCS', 'name' => 'Pieces', 'abbreviation' => 'PCS', 'is_active' => true]);

        $payload = [
            'request_no' => 'PR-TEST-001',
            'department_id' => $department->id,
            'requester_id' => $admin->id,
            'required_date' => now()->addDays(5)->format('Y-m-d'),
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'lines' => [
                [
                    'description' => 'Test Item 1',
                    'quantity' => 10,
                    'unit_id' => $unit->id,
                    'estimated_unit_cost' => 1000,
                ],
                [
                    'description' => 'Test Item 2',
                    'quantity' => 5,
                    'unit_id' => $unit->id,
                    'estimated_unit_cost' => 2000,
                ]
            ]
        ];

        $response = $this->actingAs($admin)->postJson(route('purchasing.purchase-requests.store'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.request_no', 'PR-TEST-001')
            ->assertJsonPath('data.estimated_total', '20000.00')
            ->assertJsonCount(2, 'data.lines');

        $this->assertDatabaseHas('purchase_requests', [
            'request_no' => 'PR-TEST-001',
            'property_id' => $property->id,
            'estimated_total' => 20000,
            'status' => PurchaseRequestStatusEnum::Draft->value,
        ]);
    }

    public function test_can_update_purchase_request_in_draft_status()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $pr = $this->createPurchaseRequest($property);
        $line = $this->createPurchaseRequestLine($pr);

        $payload = [
            'remarks' => 'Updated Remarks',
            'lines' => [
                [
                    'description' => 'Updated Line',
                    'quantity' => 20,
                    'unit_id' => $line->unit_id,
                    'estimated_unit_cost' => 100,
                ]
            ]
        ];

        $response = $this->actingAs($admin)->putJson(route('purchasing.purchase-requests.update', $pr->id), $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.remarks', 'Updated Remarks')
            ->assertJsonPath('data.estimated_total', '2000.00');

        $this->assertDatabaseHas('purchase_request_lines', [
            'purchase_request_id' => $pr->id,
            'description' => 'Updated Line',
        ]);
        
        $this->assertSoftDeleted('purchase_request_lines', [
            'id' => $line->id, // previous line is deleted/replaced
        ]);
    }

    public function test_can_cancel_purchase_request()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $pr = $this->createPurchaseRequest($property);

        $response = $this->actingAs($admin)->postJson(route('purchasing.purchase-requests.cancel', $pr->id));

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PurchaseRequestStatusEnum::Cancelled->value);

        $this->assertDatabaseHas('purchase_requests', [
            'id' => $pr->id,
            'status' => PurchaseRequestStatusEnum::Cancelled->value,
        ]);
    }

    public function test_property_isolation_for_purchase_requests()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);
        
        $adminA = $this->createPropertyAdmin($propertyA);
        
        $prB = $this->createPurchaseRequest($propertyB);

        $response = $this->actingAs($adminA)->getJson(route('purchasing.purchase-requests.show', $prB->id));

        $response->assertStatus(404);
    }

    public function test_user_without_permission_cannot_create_purchase_request()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $user = $this->createUser($property); // no roles
        
        $department = $this->createDepartment($property);
        $unit = InventoryUnit::create(['property_id' => $property->id, 'unit_code' => 'PCS', 'name' => 'Pieces', 'abbreviation' => 'PCS', 'is_active' => true]);

        $payload = [
            'request_no' => 'PR-UNAUTH',
            'department_id' => $department->id,
            'requester_id' => $user->id,
            'required_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_id' => $unit->id,
                    'estimated_unit_cost' => 1,
                ]
            ]
        ];

        $response = $this->actingAs($user)->postJson(route('purchasing.purchase-requests.store'), $payload);

        $response->assertStatus(403);
    }

    public function test_audit_log_created_for_purchase_request()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $pr = $this->createPurchaseRequest($property);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PurchaseRequest::class,
            'auditable_id' => $pr->id,
            'event' => 'created',
        ]);
        
        $this->actingAs($admin)->putJson(route('purchasing.purchase-requests.update', $pr->id), [
            'remarks' => 'Trigger audit'
        ]);
        
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PurchaseRequest::class,
            'auditable_id' => $pr->id,
            'event' => 'updated',
        ]);
    }
}
