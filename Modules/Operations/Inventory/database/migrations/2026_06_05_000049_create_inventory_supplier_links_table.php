<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_supplier_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('item_id')->index();
            $table->ulid('supplier_id')->index();
            $table->boolean('is_preferred')->default(false);
            $table->integer('lead_time_days')->default(0);
            $table->decimal('last_purchase_cost', 15, 2)->default(0);
            $table->timestamp('last_purchase_date')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_supplier_links'); }
};