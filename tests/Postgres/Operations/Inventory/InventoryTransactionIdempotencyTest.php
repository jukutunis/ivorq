<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

class InventoryTransactionIdempotencyTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_duplicate_non_null_property_idempotency_key_is_rejected(): void
    {
        $propertyId = (string) Str::ulid();

        $validData = [
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
            'idempotency_key' => 'idemp-789',
            'valuation_approval_status' => 'approved',
            'valuation_approval_reference' => 'inventory_receipt:test:posted',
            'business_date' => now()->toDateString(),
            'occurred_at' => now(),
            'source_document_type' => 'receipt',
            'source_document_id' => (string) Str::ulid(),
            'source_line_type' => 'receipt_line',
            'source_line_id' => (string) Str::ulid(),
            'movement_role' => 'receive',
        ];

        // Insert first time successfully
        DB::table('inventory_transactions')->insert($validData);

        // Attempt duplicate insertion
        $duplicateData = $validData;
        $duplicateData['id'] = (string) Str::ulid(); // New UUID but same idempotency_key

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/idx_inventory_transactions_idempotency/');

        DB::table('inventory_transactions')->insert($duplicateData);
    }
}
