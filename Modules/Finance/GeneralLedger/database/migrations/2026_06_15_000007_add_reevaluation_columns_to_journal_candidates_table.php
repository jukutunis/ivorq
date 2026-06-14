<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_candidates', function (Blueprint $table) {
            $table->ulid('reevaluated_by')->nullable()->after('rejection_reason');
            $table->timestamp('reevaluated_at')->nullable()->after('reevaluated_by');
            $table->integer('reevaluation_count')->default(0)->after('reevaluated_at');
            $table->text('last_reevaluation_error')->nullable()->after('reevaluation_count');
        });
    }

    public function down(): void
    {
        Schema::table('journal_candidates', function (Blueprint $table) {
            $table->dropColumn(['reevaluated_by', 'reevaluated_at', 'reevaluation_count', 'last_reevaluation_error']);
        });
    }
};
