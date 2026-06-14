<?php

namespace Tests\Feature\Operations\Purchasing;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Foundation\Property\Models\Property;

class VendorTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_can_create_vendor_with_valid_category()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST',
            'name' => 'Test Category',
        ]);

        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-001',
            'name' => 'Test Vendor',
            'is_active' => true,
        ]);

        $this->assertNotNull($vendor->id);
        $this->assertEquals('V-001', $vendor->vendor_code);
        $this->assertEquals($category->id, $vendor->vendor_category_id);
    }

    public function test_vendor_can_have_contacts()
    {
        $property = $this->createProperty($this->createCompany());
        $category = VendorCategory::create([
            'property_id' => $property->id,
            'category_code' => 'TEST2',
            'name' => 'Test Category 2',
        ]);

        $vendor = Vendor::create([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => 'V-002',
            'name' => 'Test Vendor 2',
        ]);

        $contact = $vendor->contacts()->create([
            'property_id' => $property->id,
            'contact_name' => 'John Doe',
            'email' => 'john@test.com',
            'is_primary' => true,
        ]);

        $this->assertNotNull($contact->id);
        $this->assertEquals('John Doe', $contact->contact_name);
        $this->assertEquals($vendor->id, $contact->vendor_id);
        $this->assertTrue($contact->is_primary);
    }
}
