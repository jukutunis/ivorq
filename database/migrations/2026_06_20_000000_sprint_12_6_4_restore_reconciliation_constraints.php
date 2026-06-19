<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            // Drop standard index introduced in Sprint 12.6.3
            $table->dropIndex(['bank_statement_line_id']);

            // Restore unique constraints to align with BR-007 and BR-008
            $table->unique('bank_statement_line_id', 'reconciliation_matches_bank_statement_line_id_unique');
            $table->unique(['matchable_type', 'matchable_id'], 'unique_reconciled_matchable');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            // Drop restored unique constraints
            $table->dropUnique('reconciliation_matches_bank_statement_line_id_unique');
            $table->dropUnique('unique_reconciled_matchable');

            // Restore normal index on bank_statement_line_id to return to Sprint 12.6.3 state
            $table->index('bank_statement_line_id');
        });
    }
};
