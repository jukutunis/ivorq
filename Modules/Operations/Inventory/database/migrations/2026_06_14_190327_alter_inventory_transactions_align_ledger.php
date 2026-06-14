<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->decimal('quantity_before', 15, 4)->after('quantity')->nullable();
            $table->renameColumn('quantity', 'quantity_change');
            $table->decimal('quantity_after', 15, 4)->after('quantity'); // this will be after quantity_change effectively
            $table->decimal('total_cost', 15, 2)->after('unit_cost')->nullable();
            
            $table->ulid('posted_by')->nullable()->after('created_by');
            $table->timestamp('posted_at')->nullable()->after('posted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['quantity_before', 'quantity_after', 'total_cost', 'posted_by', 'posted_at']);
            $table->renameColumn('quantity_change', 'quantity');
        });
    }
};
