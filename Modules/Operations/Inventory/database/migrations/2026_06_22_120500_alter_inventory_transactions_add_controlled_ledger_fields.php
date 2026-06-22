<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->date('business_date')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->string('source_document_type')->nullable();
            $table->ulid('source_document_id')->nullable();
            $table->string('source_line_type')->nullable();
            $table->ulid('source_line_id')->nullable();
            $table->string('movement_role')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->ulid('reverses_inventory_transaction_id')->nullable();
            $table->ulid('corrects_inventory_transaction_id')->nullable();

            $table->foreign('reverses_inventory_transaction_id')
                ->references('id')->on('inventory_transactions')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign('corrects_inventory_transaction_id')
                ->references('id')->on('inventory_transactions')
                ->onUpdate('restrict')
                ->onDelete('restrict');
        });

        // Partial unique idempotency index
        DB::statement('CREATE UNIQUE INDEX idx_inventory_transactions_idempotency ON inventory_transactions (property_id, idempotency_key) WHERE idempotency_key IS NOT NULL;');

        // Mandatory null-safe controlled-entry CHECK constraint
        DB::statement('
            ALTER TABLE inventory_transactions
            ADD CONSTRAINT chk_inventory_transactions_controlled_entry
            CHECK (
                (
                    idempotency_key IS NULL
                    OR (
                        business_date IS NOT NULL
                        AND occurred_at IS NOT NULL
                        AND source_document_type IS NOT NULL
                        AND source_document_id IS NOT NULL
                        AND source_line_type IS NOT NULL
                        AND source_line_id IS NOT NULL
                        AND movement_role IS NOT NULL
                    )
                ) IS TRUE
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['reverses_inventory_transaction_id']);
            $table->dropForeign(['corrects_inventory_transaction_id']);
        });

        DB::statement('DROP INDEX idx_inventory_transactions_idempotency;');
        DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT chk_inventory_transactions_controlled_entry;');

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'business_date',
                'occurred_at',
                'source_document_type',
                'source_document_id',
                'source_line_type',
                'source_line_id',
                'movement_role',
                'idempotency_key',
                'reverses_inventory_transaction_id',
                'corrects_inventory_transaction_id',
            ]);
        });
    }
};
