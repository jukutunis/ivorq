<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('item_id')->index();
            $table->ulid('location_id')->index();
            $table->string('transaction_type')->index();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->ulid('reference_id')->nullable()->index(); // WO, PM, Count ID
            $table->string('reference_type')->nullable();
            $table->string('notes')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps(); // immutable
        });
        // Note: Partitioning logic will be applied manually or natively in PG 14+
    }
    public function down(): void { Schema::dropIfExists('inventory_transactions'); }
};