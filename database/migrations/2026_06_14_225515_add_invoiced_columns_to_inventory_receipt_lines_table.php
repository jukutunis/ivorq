<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_receipt_lines', function (Blueprint $table) {
            $table->decimal('invoiced_quantity', 15, 3)->default(0)->after('quantity');
            $table->decimal('invoiced_amount', 15, 3)->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['invoiced_quantity', 'invoiced_amount']);
        });
    }
};
