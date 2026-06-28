<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

class InventoryTransactionReversalSchemaContractTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_postgres_test_target_verified(): void
    {
        $appEnv = config('app.env');
        $dbConn = config('database.default');
        $dbName = config('database.connections.pgsql.database');

        $this->assertEquals('testing', $appEnv);
        $this->assertEquals('pgsql', $dbConn);
        $this->assertEquals('ivorq_testing', $dbName);
    }

    public function test_anti_double_reversal_partial_unique_index_exists(): void
    {
        $index = DB::selectOne("
            SELECT indexdef
            FROM pg_indexes
            WHERE tablename = 'inventory_transactions'
              AND indexname = 'idx_inventory_transactions_reversal_limit'
        ");

        $this->assertNotNull($index, 'Index idx_inventory_transactions_reversal_limit must exist');

        $def = strtolower($index->indexdef);
        $this->assertStringContainsString('unique index', $def);
        $this->assertStringContainsString('reverses_inventory_transaction_id', $def);
        $this->assertStringContainsString('where (reverses_inventory_transaction_id is not null)', $def);
    }

    public function test_self_reversal_check_constraint_exists(): void
    {
        $constraint = DB::selectOne("
            SELECT pg_get_constraintdef(c.oid) as def
            FROM pg_constraint c
            JOIN pg_class t ON c.conrelid = t.oid
            WHERE t.relname = 'inventory_transactions'
              AND c.conname = 'chk_inventory_transactions_no_self_reversal'
              AND c.contype = 'c'
        ");

        $this->assertNotNull($constraint, 'CHECK constraint chk_inventory_transactions_no_self_reversal must exist');

        $def = strtolower(str_replace([' ', '"'], '', $constraint->def));
        $this->assertStringContainsString('reverses_inventory_transaction_id', $def);
        $this->assertStringContainsString('id', $def);

        // Assert constraint logic: is null OR <> id
        $this->assertStringContainsString('reverses_inventory_transaction_idisnull', $def);
        $this->assertStringContainsString('reverses_inventory_transaction_id<>id', $def);
    }
}
