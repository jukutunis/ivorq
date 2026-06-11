<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('sku')->index();
            $table->string('name');
            $table->ulid('category_id')->index();
            $table->string('inventory_type')->index();
            $table->string('criticality')->default('low');
            $table->boolean('is_batch_tracked')->default(false);
            $table->boolean('is_expiry_tracked')->default(false);
            $table->decimal('weighted_average_cost', 15, 2)->default(0);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_items'); }
};