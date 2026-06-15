<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 14, 2)->default(0)->after('grand_total_amount');
            $table->decimal('amount_remaining', 14, 2)->default(0)->after('amount_paid');
            $table->string('payment_status', 30)->default('UNPAID')->after('amount_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('ap_invoices', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'amount_remaining', 'payment_status']);
        });
    }
};
