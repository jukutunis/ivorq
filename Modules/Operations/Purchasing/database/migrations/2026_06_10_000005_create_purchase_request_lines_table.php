<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('purchase_request_id', 26);
            $table->char('inventory_item_id', 26)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 14, 3);
            $table->char('unit_id', 26);
            $table->decimal('estimated_unit_cost', 14, 2)->default(0);
            $table->decimal('estimated_total_cost', 14, 2)->default(0);
            $table->text('remarks')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->cascadeOnDelete();
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete();
            $table->foreign('unit_id')->references('id')->on('inventory_units')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('purchase_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
    }
};
