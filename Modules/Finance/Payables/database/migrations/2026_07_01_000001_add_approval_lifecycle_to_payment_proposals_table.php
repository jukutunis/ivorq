<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proposals', function (Blueprint $table) {
            $table->ulid('submitted_by')->nullable()->after('total_amount')->index();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->ulid('approved_by')->nullable()->after('submitted_at')->index();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->ulid('rejected_by')->nullable()->after('approved_at')->index();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_proposals', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_by',
                'submitted_at',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
