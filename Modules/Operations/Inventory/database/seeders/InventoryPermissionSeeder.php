<?php

namespace Modules\Operations\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class InventoryPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Categories (master data)
            'inventory.category.view',
            'inventory.category.create',
            'inventory.category.edit',
            'inventory.category.delete',

            // Units (master data)
            'inventory.unit.view',
            'inventory.unit.create',
            'inventory.unit.edit',
            'inventory.unit.delete',

            // Locations
            'inventory.location.view',
            'inventory.location.create',
            'inventory.location.edit',
            'inventory.location.delete',

            // Items
            'inventory.item.view',
            'inventory.item.create',
            'inventory.item.edit',
            'inventory.item.delete',

            // Receipts
            'inventory.receipt.view',
            'inventory.receipt.create',
            'inventory.receipt.edit',
            'inventory.receipt.delete',
            'inventory.receipt.post',     // finalises stock (BR-032)

            // Issues
            'inventory.issue.view',
            'inventory.issue.create',
            'inventory.issue.edit',
            'inventory.issue.delete',
            'inventory.issue.post',       // deducts stock (BR-042)

            // Transfers
            'inventory.transfer.view',
            'inventory.transfer.create',
            'inventory.transfer.edit',
            'inventory.transfer.delete',
            'inventory.transfer.complete', // moves stock between locations (BR-053)

            // Adjustments
            'inventory.adjustment.view',
            'inventory.adjustment.create',
            'inventory.adjustment.edit',
            'inventory.adjustment.delete',
            'inventory.adjustment.approve', // applies stock + costing (BR-064)
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
