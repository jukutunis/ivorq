<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'cost_ledger_entries migration requires PostgreSQL. ' .
                'Unsupported driver: "' . DB::connection()->getDriverName() . '".'
            );
        }

        // Remove prior-schema triggers and function before dropping the table.
        // The DROP TABLE below also removes triggers attached to it, but the
        // trigger function must be dropped separately.
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_prevent_cost_ledger_update ON cost_ledger_entries;
            DROP TRIGGER IF EXISTS trg_prevent_cost_ledger_delete ON cost_ledger_entries;
            DROP FUNCTION IF EXISTS prevent_cost_ledger_mutation();
        ");

        // Drop prior-schema table (schema superseded by this migration).
        Schema::dropIfExists('cost_ledger_entries');

        Schema::create('cost_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Scope identity — immutable after creation
            $table->char('property_id', 26);
            $table->char('location_id', 26);
            $table->char('item_id', 26);
            $table->string('valuation_scope');

            // Sequence within the scope — must be positive (CHECK added below)
            $table->unsignedBigInteger('valuation_sequence');

            // Source evidence reference — no cascade-delete FK preserves ledger evidence
            $table->char('inventory_transaction_id', 26);

            $table->date('business_date');

            // Before/after AVCO state — decimal(15,4) matches AvcoDecimal::SCALE = 4
            // and the CostAvcoState carrying_value / weighted_average_unit_cost precision
            $table->decimal('quantity_before', 15, 4);
            $table->decimal('quantity_after', 15, 4);
            $table->decimal('carrying_value_before', 15, 4);
            $table->decimal('carrying_value_after', 15, 4);
            $table->decimal('weighted_average_unit_cost_after', 15, 4);

            // Append-only timestamp — no updated_at column
            $table->timestamp('created_at')->useCurrent();

            // Identity: one entry per property + valuation scope + sequence
            $table->unique(
                ['property_id', 'valuation_scope', 'valuation_sequence'],
                'uk_cost_ledger_identity'
            );

            // Evidence: one entry per source inventory transaction
            $table->unique('inventory_transaction_id', 'uk_cost_ledger_transaction');
        });

        DB::unprepared("
            ALTER TABLE cost_ledger_entries
                ADD CONSTRAINT chk_cost_ledger_valuation_sequence_positive
                CHECK (valuation_sequence > 0);

            CREATE OR REPLACE FUNCTION prevent_cost_ledger_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'Cost Ledger entries are immutable and cannot be updated or deleted.';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_prevent_cost_ledger_update
                BEFORE UPDATE ON cost_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_cost_ledger_mutation();

            CREATE TRIGGER trg_prevent_cost_ledger_delete
                BEFORE DELETE ON cost_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_cost_ledger_mutation();
        ");
    }

    public function down(): void
    {
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_prevent_cost_ledger_update ON cost_ledger_entries;
            DROP TRIGGER IF EXISTS trg_prevent_cost_ledger_delete ON cost_ledger_entries;
            DROP FUNCTION IF EXISTS prevent_cost_ledger_mutation();
        ");
        Schema::dropIfExists('cost_ledger_entries');
    }
};
