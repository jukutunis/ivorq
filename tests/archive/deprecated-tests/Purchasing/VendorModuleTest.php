<?php

namespace Tests\Feature\Operations\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Audit\Models\AuditLog;
use Tests\Feature\Operations\Concerns\CreatesPurchasingData;
use Tests\TestCase;

class VendorModuleTest extends TestCase
{
    use RefreshDatabase, CreatesPurchasingData;

    protected function setUp(): void
    {
        parent::setUp();
        // Since we created the permission seeder, we must run it
        $this->seedPurchasingPermissions();
    }

    public function test_can_list_vendor_categories()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $this->createVendorCategory($property, ['name' => 'F&B']);
        $this->createVendorCategory($property, ['name' => 'Engineering']);

        $response = $this->actingAs($admin)->getJson(route('purchasing.vendor-categories.index'));

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Engineering') // ordered by latest
            ->assertJsonPath('data.1.name', 'F&B');
    }

    public function test_can_create_vendor_category_with_audit_log()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $payload = [
            'category_code' => 'VC-001',
            'name' => 'Test Category',
            'is_active' => true,
        ];

        $response = $this->actingAs($admin)->postJson(route('purchasing.vendor-categories.store'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Test Category');

        $this->assertDatabaseHas('vendor_categories', [
            'category_code' => 'VC-001',
            'property_id' => $property->id,
        ]);

        // Assert Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => VendorCategory::class,
            'event' => 'created',
            'user_id' => $admin->id,
        ]);
    }

    public function test_can_create_vendor_with_contacts_and_audit_log()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $category = $this->createVendorCategory($property);

        $payload = [
            'vendor_category_id' => $category->id,
            'vendor_code' => 'VND-001',
            'name' => 'PT Test Vendor',
            'tax_id' => '00.000.000.0-000.000',
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'contacts' => [
                [
                    'contact_name' => 'Primary Contact',
                    'email' => 'primary@example.com',
                    'phone' => '12345',
                    'is_primary' => true,
                ],
                [
                    'contact_name' => 'Secondary Contact',
                    'email' => 'sec@example.com',
                    'phone' => '67890',
                    'is_primary' => false,
                ]
            ]
        ];

        $response = $this->actingAs($admin)->postJson(route('purchasing.vendors.store'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'PT Test Vendor')
            ->assertJsonCount(2, 'data.contacts');

        $vendorId = $response->json('data.id');

        $this->assertDatabaseHas('vendors', [
            'id' => $vendorId,
            'vendor_code' => 'VND-001',
            'property_id' => $property->id,
        ]);

        $this->assertDatabaseHas('vendor_contacts', [
            'vendor_id' => $vendorId,
            'contact_name' => 'Primary Contact',
            'is_primary' => 1,
        ]);

        // Assert Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Vendor::class,
            'event' => 'created',
        ]);
    }

    public function test_can_update_vendor_and_contacts()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $category = $this->createVendorCategory($property);
        $vendor = $this->createVendor($property, $category);
        $contact = $this->createVendorContact($vendor);

        $payload = [
            'name' => 'Updated Vendor Name',
            'contacts' => [
                [
                    'id' => $contact->id,
                    'contact_name' => 'Updated Contact Name',
                    'is_primary' => true,
                ],
                [
                    'contact_name' => 'New Contact',
                    'email' => 'new@test.com',
                ]
            ]
        ];

        $response = $this->actingAs($admin)->putJson(route('purchasing.vendors.update', $vendor->id), $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Vendor Name')
            ->assertJsonCount(2, 'data.contacts');

        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'name' => 'Updated Vendor Name',
        ]);

        $this->assertDatabaseHas('vendor_contacts', [
            'id' => $contact->id,
            'contact_name' => 'Updated Contact Name',
        ]);

        $this->assertDatabaseHas('vendor_contacts', [
            'vendor_id' => $vendor->id,
            'contact_name' => 'New Contact',
        ]);
    }

    public function test_can_toggle_vendor_approval()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        $admin = $this->createPropertyAdmin($property);

        $category = $this->createVendorCategory($property);
        $vendor = $this->createVendor($property, $category, ['is_approved' => false]);

        $response = $this->actingAs($admin)->postJson(route('purchasing.vendors.approve', $vendor->id));

        $response->assertStatus(200)
            ->assertJsonPath('data.is_approved', true);

        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'is_approved' => 1,
        ]);
    }

    public function test_property_isolation_prevents_access_to_other_property_vendors()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);
        
        $adminA = $this->createPropertyAdmin($propertyA);
        
        $categoryB = $this->createVendorCategory($propertyB);
        $vendorB = $this->createVendor($propertyB, $categoryB);

        // Admin of Property A tries to view Vendor of Property B
        $response = $this->actingAs($adminA)->getJson(route('purchasing.vendors.show', $vendorB->id));

        // It should either return 403 (policy) or 404 (global scope)
        // BelongsToProperty uses global scope, so it should be 404 Not Found.
        $response->assertStatus(404);
    }

    public function test_user_without_permission_cannot_create_vendor()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        // Regular user without 'purchasing.vendor.create' permission
        $user = $this->createUser($property);
        
        $category = $this->createVendorCategory($property);

        $payload = [
            'vendor_category_id' => $category->id,
            'vendor_code' => 'VND-002',
            'name' => 'Unauth Vendor',
        ];

        $response = $this->actingAs($user)->postJson(route('purchasing.vendors.store'), $payload);

        $response->assertStatus(403);
    }
}
