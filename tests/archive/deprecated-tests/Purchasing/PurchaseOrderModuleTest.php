<?php

namespace Tests\Feature\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\Vendor;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class PurchaseOrderModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected User $user;
    protected $property;
    protected Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPurchasingPermissions();
        
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->user = $this->createPropertyAdmin($this->property);
        $category = $this->createVendorCategory($this->property);
        $this->vendor = $this->createVendor($this->property, $category, [
            'is_active' => true,
            'is_approved' => true,
        ]);
    }

    public function test_can_create_po_from_approved_pr()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
            'remarks' => 'Test PO Creation'
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', PurchaseOrderStatusEnum::Draft->value);

        $this->assertDatabaseHas('purchase_orders', [
            'purchase_request_id' => $pr->id,
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatusEnum::Draft->value,
            'property_id' => $this->property->id,
        ]);
    }

    public function test_cannot_create_po_from_unapproved_pr()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Submitted->value,
        ]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Purchase Order can only be created from an Approved Purchase Request.');
    }

    public function test_vendor_must_be_active()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $category = $this->createVendorCategory($this->property);
        $inactiveVendor = $this->createVendor($this->property, $category, [
            'is_active' => false,
            'is_approved' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $inactiveVendor->id,
            'purchase_request_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Selected Vendor is either inactive or not approved (blacklisted).');
    }

    public function test_po_generates_unique_number()
    {
        $pr1 = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $response1 = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr1->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $poNo1 = $response1->json('data.po_no');
        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{6}$/', $poNo1);

        $pr2 = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $response2 = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr2->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $poNo2 = $response2->json('data.po_no');
        $this->assertNotEquals($poNo1, $poNo2);
    }

    public function test_only_one_po_per_pr()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'A Purchase Order has already been created for this Purchase Request.');
    }

    public function test_can_issue_po()
    {
        $po = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $this->createPurchaseRequest($this->property)->id,
            'status' => PurchaseOrderStatusEnum::Draft->value,
        ]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.issue', $po->id));

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PurchaseOrderStatusEnum::Issued->value);
    }

    public function test_can_cancel_po()
    {
        $po = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $this->createPurchaseRequest($this->property)->id,
            'status' => PurchaseOrderStatusEnum::Issued->value,
        ]);

        $response = $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.cancel', $po->id));

        $response->assertStatus(200)
            ->assertJsonPath('data.status', PurchaseOrderStatusEnum::Cancelled->value);
    }

    public function test_po_status_lock_test()
    {
        $po = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id,
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $this->createPurchaseRequest($this->property)->id,
            'status' => PurchaseOrderStatusEnum::PartiallyReceived->value,
        ]);

        $response = $this->actingAs($this->user)->putJson(route('purchasing.purchase-orders.update', $po->id), [
            'remarks' => 'Trying to edit'
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Purchase Order cannot be edited in its current status.');
    }

    public function test_audit_log_created_for_po()
    {
        $pr = $this->createPurchaseRequest($this->property, [
            'status' => PurchaseRequestStatusEnum::Approved->value,
        ]);

        $this->actingAs($this->user)->postJson(route('purchasing.purchase-orders.store'), [
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $pr->id,
            'expected_delivery_date' => now()->addDays(7)->format('Y-m-d'),
            'remarks' => 'Audit Test'
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PurchaseOrder::class,
            'event' => 'created',
        ]);
    }

    public function test_multi_property_isolation()
    {
        $otherCompany = $this->createCompany();
        $otherProperty = $this->createProperty($otherCompany);
        $otherUser = $this->createPropertyAdmin($otherProperty);

        $po = PurchaseOrder::factory()->create([
            'property_id' => $this->property->id, // Belongs to property 1
            'vendor_id' => $this->vendor->id,
            'purchase_request_id' => $this->createPurchaseRequest($this->property)->id,
            'status' => PurchaseOrderStatusEnum::Draft->value,
        ]);

        // otherUser tries to issue property 1's PO
        $response = $this->actingAs($otherUser)->postJson(route('purchasing.purchase-orders.issue', $po->id));
        
        $response->assertStatus(404); // 404 because Global Scope (BelongsToProperty) prevents it from being found
    }
}
