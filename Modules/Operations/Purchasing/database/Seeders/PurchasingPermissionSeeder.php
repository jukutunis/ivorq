<?php

namespace Modules\Operations\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PurchasingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Vendor Categories
            'purchasing.vendor-category.view',
            'purchasing.vendor-category.create',
            'purchasing.vendor-category.edit',
            'purchasing.vendor-category.delete',

            // Vendors
            'purchasing.vendor.view',
            'purchasing.vendor.create',
            'purchasing.vendor.edit',
            'purchasing.vendor.delete',
            'purchasing.vendor.approve',

            // Vendor Contacts
            'purchasing.vendor-contact.view',
            'purchasing.vendor-contact.create',
            'purchasing.vendor-contact.edit',
            'purchasing.vendor-contact.delete',

            // Purchase Requests
            'purchasing.purchase-request.view',
            'purchasing.purchase-request.create',
            'purchasing.purchase-request.edit',
            'purchasing.purchase-request.delete',
            'purchasing.purchase-request.cancel',

            // Purchase Orders
            'purchasing.purchase-order.view',
            'purchasing.purchase-order.create',
            'purchasing.purchase-order.edit',
            'purchasing.purchase-order.delete',
            'purchasing.purchase-order.approve',
            'purchasing.purchase-order.issue',
            'purchasing.purchase-order.cancel',
            // Goods Receipt Notes
            'goods-receipt.view-any',
            'goods-receipt.view',
            'goods-receipt.create',
            'goods-receipt.edit',
            'goods-receipt.delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
