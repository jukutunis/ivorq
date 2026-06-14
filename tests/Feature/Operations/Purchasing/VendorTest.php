<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Illuminate\Database\QueryException;

class VendorTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_create_and_activate_vendor()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);

        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'VND-001',
            'name' => 'Tech Corp',
            'tax_number' => 'TAX-1234',
            'contact_person' => 'John Doe',
            'email' => 'john@techcorp.com',
            'phone' => '1234567890',
            'payment_term_days' => 30,
            'credit_limit' => 50000,
            'is_active' => false,
            'is_approved' => false,
        ]);

        $this->assertFalse($vendor->is_active);
        $this->assertFalse($vendor->is_approved);

        $vendor->update(['is_active' => true]);
        $this->assertTrue($vendor->fresh()->is_active);
    }

    public function test_vendor_approval()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);

        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'VND-002',
            'name' => 'Supply Co',
            'is_active' => true,
            'is_approved' => false,
        ]);

        $vendor->update(['is_approved' => true]);
        $this->assertTrue($vendor->fresh()->is_approved);
    }

    public function test_property_isolation_on_vendor_code()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);

        $categoryA = VendorCategory::create(['property_id' => $propertyA->id, 'name' => 'IT', 'category_code' => 'IT-A']);
        $categoryB = VendorCategory::create(['property_id' => $propertyB->id, 'name' => 'IT', 'category_code' => 'IT-B']);

        Vendor::create([
            'property_id' => $propertyA->id,
            'vendor_category_id' => $categoryA->id,
            'vendor_code' => 'VND-ISOLATION',
            'name' => 'Vendor A',
        ]);

        // Same vendor code on a different property should be allowed (Property Isolation)
        $vendorB = Vendor::create([
            'property_id' => $propertyB->id,
            'vendor_category_id' => $categoryB->id,
            'vendor_code' => 'VND-ISOLATION',
            'name' => 'Vendor B',
        ]);

        $this->assertNotNull($vendorB->id);
    }

    public function test_duplicate_vendor_code_prevention_on_same_property()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create(['property_id' => $property->id, 'name' => 'IT', 'category_code' => 'IT']);

        Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'VND-DUP',
            'name' => 'Original Vendor',
        ]);

        $this->expectException(QueryException::class);

        // Same vendor code on the same property should throw constraint violation
        Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'VND-DUP',
            'name' => 'Duplicate Vendor',
        ]);
    }
}
