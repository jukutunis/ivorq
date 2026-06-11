<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_reorder_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('item_id')->index();
            $table->ulid('location_id')->nullable()->index();
            $table->decimal('min_stock', 15, 4)->default(0);
            $table->decimal('max_stock', 15, 4)->default(0);
            $table->decimal('safety_stock', 15, 4)->default(0);
            $table->decimal('reorder_point', 15, 4)->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_reorder_rules'); }
};