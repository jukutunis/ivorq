<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

class CostLedgerPostgresSchemaContractTest extends PostgresTestCase
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
        
        $currentDb = DB::selectOne('SELECT current_database() as db')->db;
        $this->assertEquals('ivorq_testing', $currentDb);

        // POSTGRES_TEST_TARGET_VERIFIED
    }

    public function test_cost_ledger_entries_table_exists(): void
    {
        $exists = DB::selectOne("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = 'cost_ledger_entries'
            ) as e
        ")->e;

        $this->assertTrue($exists, 'Table cost_ledger_entries must exist');
    }

    public function test_numeric_precision_and_scale(): void
    {
        $columns = ['quantity_delta', 'unit_cost', 'value_delta'];
        
        foreach ($columns as $column) {
            $colData = DB::selectOne("
                SELECT numeric_precision, numeric_scale, data_type
                FROM information_schema.columns
                WHERE table_name = 'cost_ledger_entries' AND column_name = ?
            ", [$column]);

            $this->assertNotNull($colData, "Column $column must exist");
            $this->assertEquals('numeric', $colData->data_type);
            $this->assertEquals(15, $colData->numeric_precision, "$column numeric_precision must be 15");
            $this->assertEquals(4, $colData->numeric_scale, "$column numeric_scale must be 4");
        }
    }

    public function test_required_migration_columns_present(): void
    {
        $expectedColumns = [
            'id', 'property_id', 'source_inventory_transaction_id', 'prior_cost_ledger_entry_id',
            'entry_type', 'idempotency_key', 'entry_sequence', 'currency_code',
            'quantity_delta', 'unit_cost', 'value_delta', 'business_date',
            'occurred_at', 'original_business_date', 'metadata', 'created_at'
        ];

        $actualColumns = array_column(DB::select("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'cost_ledger_entries'
        "), 'column_name');

        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $actualColumns, "Missing required column: $col");
        }
    }

    public function test_exact_entry_type_constraint(): void
    {
        $constraint = DB::selectOne("
            SELECT pg_get_constraintdef(c.oid) as def
            FROM pg_constraint c
            JOIN pg_class t ON c.conrelid = t.oid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
            WHERE t.relname = 'cost_ledger_entries' 
            AND a.attname = 'entry_type'
            AND c.contype = 'c'
        ");

        $this->assertNotNull($constraint, 'No check constraint found for entry_type');
        
        $def = strtolower(str_replace([' ', '"'], '', $constraint->def));
        $this->assertStringContainsString('entry_type', $def);
        
        $expectedValues = ['receipt', 'issue', 'adjustment', 'transfer', 'correction', 'reversal'];
        foreach ($expectedValues as $val) {
            $this->assertStringContainsString("'$val'", $def, "Entry type check constraint missing required value: $val");
        }
        
        // Ensure no other values exist in the IN clause
        preg_match('/in\((.*?)\)/', $def, $matches);
        if (!empty($matches[1])) {
            $actualValuesStr = str_replace("'", "", $matches[1]);
            $actualValues = explode(',', $actualValuesStr);
            $actualValues = array_map('trim', array_map('strtolower', $actualValues));
            
            $unexpected = array_diff($actualValues, $expectedValues);
            $this->assertEmpty($unexpected, 'Unexpected values in entry_type constraint: ' . implode(',', $unexpected));
        }
    }

    public function test_exact_idempotency_uniqueness_constraint(): void
    {
        $constraint = DB::selectOne("
            SELECT 
                c.conname as constraint_name,
                c.contype as constraint_type,
                array_agg(a.attname ORDER BY u.ord) as columns
            FROM pg_constraint c
            JOIN pg_class t ON c.conrelid = t.oid
            CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS u(attnum, ord)
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = u.attnum
            WHERE t.relname = 'cost_ledger_entries' AND c.contype IN ('u', 'p')
            GROUP BY c.conname, c.contype
            HAVING array_agg(a.attname ORDER BY u.ord) = ARRAY['property_id', 'idempotency_key', 'entry_sequence']::name[]
        ");
        
        if (!$constraint) {
            // Alternatively, check unique indexes that don't have an associated constraint
            $index = DB::selectOne("
                SELECT 
                    i.relname as index_name,
                    array_agg(a.attname ORDER BY k.ord) as columns
                FROM pg_class t
                JOIN pg_index ix ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
                WHERE t.relname = 'cost_ledger_entries' AND ix.indisunique = true
                GROUP BY i.relname
                HAVING array_agg(a.attname ORDER BY k.ord) = ARRAY['property_id', 'idempotency_key', 'entry_sequence']::name[]
            ");
            
            $this->assertNotNull($index, 'Idempotency unique constraint or index is missing or column tuple is incorrect');
        } else {
            $this->assertNotNull($constraint, 'Idempotency unique constraint is missing or column tuple is incorrect');
        }
    }

    public function test_foreign_key_structure(): void
    {
        $fks = DB::select("
            SELECT
                kcu.column_name as local_column,
                ccu.table_name AS referenced_table,
                ccu.column_name AS referenced_column
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
              ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
              ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = 'cost_ledger_entries'
        ");

        $expectedFks = [
            'source_inventory_transaction_id' => 'inventory_transactions.id',
            'prior_cost_ledger_entry_id' => 'cost_ledger_entries.id',
        ];

        $actualFks = [];
        foreach ($fks as $fk) {
            $actualFks[$fk->local_column] = $fk->referenced_table . '.' . $fk->referenced_column;
        }

        foreach ($expectedFks as $localCol => $ref) {
            $this->assertArrayHasKey($localCol, $actualFks, "Missing foreign key for $localCol");
            $this->assertEquals($ref, $actualFks[$localCol], "Foreign key for $localCol does not reference $ref");
        }
    }

    public function test_schema_only_test_purity(): void
    {
        $path = __FILE__;
        $content = file_get_contents($path);

        $forbidden = [
            'IN' . 'SERT', 'UP' . 'DATE', 'DE' . 'LETE', 'AL' . 'TER', 'DR' . 'OP', 'CR' . 'EATE', 'TRUN' . 'CATE',
            'DB::' . 'statement', 'DB::' . 'unprepared', 'Sche' . 'ma::', 'Artisan::' . 'call',
            'CostLedgerAppend' . 'Service', 'CostLedger' . 'Repository', 'CostLedgerPosting' . 'Planner',
            'Inventory' . 'Stock', 'Inventory' . 'Transaction',
        ];

        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsString($f, $line, "Forbidden keyword found on line $i: $f");
            }
        }
    }
}