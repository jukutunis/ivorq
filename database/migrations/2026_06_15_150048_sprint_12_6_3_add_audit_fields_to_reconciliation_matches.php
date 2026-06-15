<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->dropUnique('reconciliation_matches_bank_statement_line_id_unique');
            $table->dropUnique('unique_reconciled_matchable');
            
            $table->index('bank_statement_line_id');

            $table->string('match_method')->nullable();
            $table->string('matched_by')->nullable(); // user ulid
            $table->string('override_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->dropColumn(['match_method', 'matched_by', 'override_reason']);

            $table->dropIndex(['bank_statement_line_id']);

            $table->unique('bank_statement_line_id');
            $table->unique(['matchable_type', 'matchable_id'], 'unique_reconciled_matchable');
        });
    }
};
