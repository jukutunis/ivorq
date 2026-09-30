<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'owner_activations_first_trust_run_id_foreign';

    public function up(): void
    {
        $this->assertPostgreSql();
        $this->assertRequiredTables();
        $this->lockAndAssertEmpty();
        $this->assertForeignKeyTarget('property_bootstrap_provisioning_runs');

        DB::statement('ALTER TABLE owner_activations DROP CONSTRAINT '.self::CONSTRAINT);
        DB::statement(<<<'SQL'
            ALTER TABLE owner_activations
            ADD CONSTRAINT owner_activations_first_trust_run_id_foreign
            FOREIGN KEY (first_trust_run_id) REFERENCES first_trust_runs(id)
            ON UPDATE RESTRICT ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        $this->assertPostgreSql();
        $this->assertRequiredTables();
        $this->lockAndAssertEmpty();
        $this->assertForeignKeyTarget('first_trust_runs');

        DB::statement('ALTER TABLE owner_activations DROP CONSTRAINT '.self::CONSTRAINT);
        DB::statement(<<<'SQL'
            ALTER TABLE owner_activations
            ADD CONSTRAINT owner_activations_first_trust_run_id_foreign
            FOREIGN KEY (first_trust_run_id) REFERENCES property_bootstrap_provisioning_runs(id)
            ON UPDATE RESTRICT ON DELETE RESTRICT
        SQL);
    }

    private function assertPostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('B5A2A_POSTGRESQL_REQUIRED');
        }
    }

    private function assertRequiredTables(): void
    {
        if (! Schema::hasTable('owner_activations') || ! Schema::hasTable('first_trust_runs')) {
            throw new RuntimeException('B5A2A_FIRST_TRUST_LINKAGE_SCHEMA_MISMATCH');
        }
    }

    private function lockAndAssertEmpty(): void
    {
        DB::statement('LOCK TABLE owner_activations, first_trust_runs IN ACCESS EXCLUSIVE MODE');

        if (DB::table('owner_activations')->exists() || DB::table('first_trust_runs')->exists()) {
            throw new RuntimeException('B5A2A_EXISTING_FIRST_TRUST_LINKAGE_RECONCILIATION_REQUIRED');
        }
    }

    private function assertForeignKeyTarget(string $expectedTable): void
    {
        $constraints = DB::select(<<<'SQL'
            SELECT
                constraint_record.conname AS constraint_name,
                source_attribute.attname AS source_column,
                target_table.relname AS target_table,
                target_attribute.attname AS target_column,
                constraint_record.confupdtype AS update_action,
                constraint_record.confdeltype AS delete_action,
                cardinality(constraint_record.conkey) AS source_column_count,
                cardinality(constraint_record.confkey) AS target_column_count
            FROM pg_constraint constraint_record
            JOIN pg_class source_table
              ON source_table.oid = constraint_record.conrelid
            JOIN pg_namespace source_namespace
              ON source_namespace.oid = source_table.relnamespace
            JOIN LATERAL unnest(constraint_record.conkey) WITH ORDINALITY source_key(attnum, ordinal)
              ON true
            JOIN pg_attribute source_attribute
              ON source_attribute.attrelid = source_table.oid
             AND source_attribute.attnum = source_key.attnum
            JOIN pg_class target_table
              ON target_table.oid = constraint_record.confrelid
            JOIN LATERAL unnest(constraint_record.confkey) WITH ORDINALITY target_key(attnum, ordinal)
              ON target_key.ordinal = source_key.ordinal
            JOIN pg_attribute target_attribute
              ON target_attribute.attrelid = target_table.oid
             AND target_attribute.attnum = target_key.attnum
            WHERE constraint_record.contype = 'f'
              AND source_namespace.nspname = current_schema()
              AND source_table.relname = 'owner_activations'
              AND source_attribute.attname = 'first_trust_run_id'
        SQL);

        if (count($constraints) !== 1) {
            throw new RuntimeException('B5A2A_FIRST_TRUST_LINKAGE_SCHEMA_MISMATCH');
        }

        $constraint = $constraints[0];
        if ($constraint->constraint_name !== self::CONSTRAINT
            || $constraint->source_column !== 'first_trust_run_id'
            || $constraint->target_table !== $expectedTable
            || $constraint->target_column !== 'id'
            || (int) $constraint->source_column_count !== 1
            || (int) $constraint->target_column_count !== 1
            || $constraint->update_action !== 'r'
            || $constraint->delete_action !== 'r') {
            throw new RuntimeException('B5A2A_FIRST_TRUST_LINKAGE_SCHEMA_MISMATCH');
        }
    }
};
