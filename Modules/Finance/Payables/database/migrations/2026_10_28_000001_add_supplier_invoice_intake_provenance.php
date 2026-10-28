<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->string('registration_idempotency_key', 96)->nullable()->after('invoice_number');
            $table->char('source_fingerprint', 64)->nullable()->after('registration_idempotency_key');
            $table->unique(
                ['property_id', 'registration_idempotency_key'],
                'vendor_invoice_registration_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->dropUnique('vendor_invoice_registration_idempotency_unique');
            $table->dropColumn(['registration_idempotency_key', 'source_fingerprint']);
        });
    }
};
