<?php

namespace Tests\Unit\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Operations\Inventory\Models\InventoryStock;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use Tests\TestCase;

class InventoryModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_transaction_is_immutable_by_omitting_updated_at()
    {
        $this->assertNull(InventoryTransaction::UPDATED_AT);
    }

    public function test_inventory_stock_calculates_available_quantity_correctly()
    {
        $stock = new InventoryStock([
            'physical_quantity' => 100,
            'reserved_quantity' => 30,
        ]);

        $this->assertEquals(70, $stock->available_quantity);
    }

    public function test_inventory_stock_available_quantity_never_below_zero()
    {
        $stock = new InventoryStock([
            'physical_quantity' => 10,
            'reserved_quantity' => 30,
        ]);

        $this->assertEquals(0, $stock->available_quantity);
    }
}
