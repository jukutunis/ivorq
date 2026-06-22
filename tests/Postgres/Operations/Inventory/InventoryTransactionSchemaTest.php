<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\Operations\Inventory\Models\InventoryTransaction;

class InventoryTransactionSchemaTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_legacy_shaped_null_fields_remain_accepted(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'transaction_type' => 'receipt',
            'quantity_before' => 0,
            'quantity_change' => 10,
            'quantity_after' => 10,
            'unit_cost' => 0,
            'total_cost' => 0,
            'posted_at' => now(),
            // All controlled fields including idempotency_key are null
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'property_id' => $propertyId,
            'idempotency_key' => null,
            'movement_role' => null,
        ]);
    }

    public function test_multiple_null_idempotency_keys_remain_accepted(): void
    {
        $propertyId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $locationId = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $propertyId,
                'item_id' => $itemId,
                'location_id' => $locationId,
                'transaction_type' => 'receipt',
                'quantity_before' => 0,
                'quantity_change' => 10,
                'quantity_after' => 10,
                'unit_cost' => 0,
                'total_cost' => 0,
                'posted_at' => now(),
                'idempotency_key' => null,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $propertyId,
                'item_id' => $itemId,
                'location_id' => $locationId,
                'transaction_type' => 'issue',
                'quantity_before' => 10,
                'quantity_change' => -5,
                'quantity_after' => 5,
                'unit_cost' => 0,
                'total_cost' => 0,
                'posted_at' => now(),
                'idempotency_key' => null,
            ],
        ]);

        $this->assertEquals(2, DB::table('inventory_transactions')->where('property_id', $propertyId)->count());
    }

    public function test_incomplete_controlled_entry_with_non_null_idempotency_key_is_rejected(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/chk_inventory_transactions_controlled_entry/');

        DB::table('inventory_transactions')->insert([
            'id' => (string) Str::ulid(),
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
            'idempotency_key' => 'idemp-123',
            // Missing all other controlled fields
            'business_date' => null,
        ]);
    }

    public function test_complete_controlled_entry_with_non_null_idempotency_key_is_accepted(): void
    {
        $propertyId = (string) Str::ulid();

        DB::table('inventory_transactions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $propertyId,
            'item_id' => (string) Str::ulid(),
            'location_id' => (string) Str::ulid(),
            'transaction_type' => 'receipt',
            'quantity_before' => 0,
            'quantity_change' => 10,
            'quantity_after' => 10,
            'unit_cost' => 0,
            'total_cost' => 0,
            'posted_at' => now(),
            'idempotency_key' => 'idemp-456',
            'business_date' => now()->toDateString(),
            'occurred_at' => now(),
            'source_document_type' => 'receipt',
            'source_document_id' => (string) Str::ulid(),
            'source_line_type' => 'receipt_line',
            'source_line_id' => (string) Str::ulid(),
            'movement_role' => 'receive',
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'property_id' => $propertyId,
            'idempotency_key' => 'idemp-456',
            'movement_role' => 'receive',
        ]);
    }
}
