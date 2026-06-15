<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->timestamp('matched_at')->nullable();
        });

        // Fund transfers already have status column from earlier migration


        Schema::table('reconciliation_sessions', function (Blueprint $table) {
            $table->string('reviewed_by')->nullable(); // ULID
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_matches', function (Blueprint $table) {
            $table->dropColumn(['confidence_score', 'matched_at']);
        });

        // Fund transfers status not added here


        Schema::table('reconciliation_sessions', function (Blueprint $table) {
            $table->dropColumn(['reviewed_by', 'reviewed_at']);
        });
    }
};
