<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->ulid('exception_resolved_by')->nullable()->index()->after('remarks');
            $table->timestamp('exception_resolved_at')->nullable()->after('exception_resolved_by');
            $table->text('exception_resolution_reason')->nullable()->after('exception_resolved_at');

            $table->ulid('approved_by')->nullable()->index()->after('exception_resolution_reason');
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->ulid('rejected_by')->nullable()->index()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'exception_resolved_by',
                'exception_resolved_at',
                'exception_resolution_reason',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
