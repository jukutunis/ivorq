<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_sessions', function (Blueprint $table) {
            $table->string('finalized_by', 26)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->text('finalization_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_sessions', function (Blueprint $table) {
            $table->dropColumn(['finalized_by', 'finalized_at', 'finalization_notes']);
        });
    }
};
