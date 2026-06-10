<?php

namespace Tests\Feature\Operations\Concerns;

use Modules\Operations\Purchasing\Database\Seeders\PurchasingPermissionSeeder;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Models\VendorContact;
use Modules\Foundation\Property\Models\Property;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait CreatesPurchasingData
{
    use CreatesOperationsData;

    protected function seedPurchasingPermissions(): void
    {
        $this->seed(PurchasingPermissionSeeder::class);

        foreach (['property-admin', 'super-admin'] as $roleName) {
            Role::where('name', $roleName)->first()
                ?->syncPermissions(Permission::all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function createVendorCategory(Property $property, array $overrides = []): VendorCategory
    {
        static $sequence = 0;
        $sequence++;

        return VendorCategory::create(array_merge([
            'property_id' => $property->id,
            'category_code' => "VC{$sequence}",
            'name' => "Category {$sequence}",
            'is_active' => true,
        ], $overrides));
    }

    protected function createVendor(Property $property, VendorCategory $category, array $overrides = []): Vendor
    {
        static $sequence = 0;
        $sequence++;

        return Vendor::create(array_merge([
            'property_id' => $property->id,
            'vendor_category_id' => $category->id,
            'vendor_code' => "VND{$sequence}",
            'name' => "Vendor {$sequence}",
            'tax_id' => "12.345.678.9-000.000",
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => false,
            'performance_score' => 0,
        ], $overrides));
    }

    protected function createVendorContact(Vendor $vendor, array $overrides = []): VendorContact
    {
        return VendorContact::create(array_merge([
            'vendor_id' => $vendor->id,
            'contact_name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '08123456789',
            'position' => 'Sales Manager',
            'is_primary' => true,
        ], $overrides));
    }

    protected function createPurchaseRequest(Property $property, array $overrides = []): \Modules\Operations\Purchasing\Models\PurchaseRequest
    {
        return \Modules\Operations\Purchasing\Models\PurchaseRequest::factory()->create(array_merge([
            'property_id' => $property->id,
            'department_id' => $this->createDepartment($property)->id,
            'requester_id' => $this->createUser($property)->id,
        ], $overrides));
    }

    protected function createPurchaseRequestLine(\Modules\Operations\Purchasing\Models\PurchaseRequest $pr, array $overrides = []): \Modules\Operations\Purchasing\Models\PurchaseRequestLine
    {
        return \Modules\Operations\Purchasing\Models\PurchaseRequestLine::factory()->create(array_merge([
            'purchase_request_id' => $pr->id,
            'unit_id' => \Modules\Operations\Inventory\Models\InventoryUnit::firstOrCreate(['property_id' => $pr->property_id, 'unit_code' => 'PCS'], ['name' => 'Pieces', 'abbreviation' => 'PCS', 'is_active' => true])->id,
            'inventory_item_id' => null,
        ], $overrides));
    }
}
