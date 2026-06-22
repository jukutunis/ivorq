<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class InventoryTransactionImmutabilityTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_immutable_update_trigger_behavior_remains_enforced(): void
    {
        $id = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id' => $id,
            'property_id' => (string) Str::ulid(),
            'item_id' => (string) Str::ulid(),
            'location_id' => (string) Str::ulid(),
            'transaction_type' => 'receipt',
            'quantity_before' => 0,
            'quantity_change' => 10,
            'quantity_after' => 10,
            'unit_cost' => 0,
            'total_cost' => 0,
            'posted_at' => now(),
        ]);

        $exceptionThrown = false;
        try {
            DB::table('inventory_transactions')->where('id', $id)->update(['quantity_change' => 20]);
        } catch (\Illuminate\Database\QueryException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Inventory transactions are immutable and cannot be updated or deleted.', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected an exception when updating an immutable inventory transaction.');
    }

    public function test_immutable_delete_trigger_behavior_remains_enforced(): void
    {
        $id = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id' => $id,
            'property_id' => (string) Str::ulid(),
            'item_id' => (string) Str::ulid(),
            'location_id' => (string) Str::ulid(),
            'transaction_type' => 'receipt',
            'quantity_before' => 0,
            'quantity_change' => 10,
            'quantity_after' => 10,
            'unit_cost' => 0,
            'total_cost' => 0,
            'posted_at' => now(),
        ]);

        $exceptionThrown = false;
        try {
            DB::table('inventory_transactions')->where('id', $id)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Inventory transactions are immutable and cannot be updated or deleted.', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected an exception when deleting an immutable inventory transaction.');
    }
}
