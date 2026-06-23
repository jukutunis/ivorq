<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->ulid('source_inventory_transaction_id');
            $table->ulid('prior_cost_ledger_entry_id')->nullable();
            $table->string('entry_type', 32);
            $table->string('idempotency_key', 64);
            $table->integer('entry_sequence');
            $table->string('currency_code', 3);
            $table->decimal('quantity_delta', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('value_delta', 15, 4);
            $table->date('business_date');
            $table->dateTime('occurred_at');
            $table->date('original_business_date')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('source_inventory_transaction_id')
                  ->references('id')
                  ->on('inventory_transactions')
                  ->onDelete('restrict');

            $table->unique(['property_id', 'idempotency_key', 'entry_sequence'], 'uk_cost_ledger_idempotency');
        });

        Schema::table('cost_ledger_entries', function (Blueprint $table) {
            $table->foreign('prior_cost_ledger_entry_id')
                  ->references('id')
                  ->on('cost_ledger_entries')
                  ->onDelete('restrict');
        });

        DB::unprepared("
            ALTER TABLE cost_ledger_entries
            ADD CONSTRAINT chk_cost_ledger_sequence CHECK (entry_sequence > 0);
            
            ALTER TABLE cost_ledger_entries
            ADD CONSTRAINT chk_cost_ledger_entry_type CHECK (entry_type IN ('receipt', 'issue', 'adjustment', 'transfer', 'correction', 'reversal'));

            CREATE OR REPLACE FUNCTION prevent_cost_ledger_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Cost Ledger entries are immutable and cannot be updated or deleted.';
            END;
            $$ LANGUAGE plpgsql;

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
