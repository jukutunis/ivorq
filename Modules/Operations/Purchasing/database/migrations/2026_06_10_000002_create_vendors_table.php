<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable(); // Nullable for Company level
            $table->char('company_id', 26)->nullable(); // Might be bound to Company instead of Property
            $table->char('vendor_category_id', 26);
            $table->string('vendor_code', 50);
            $table->string('name', 255);
            $table->string('tax_id', 100)->nullable();
            $table->string('default_currency_code', 10)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_approved')->default(false);
            $table->decimal('performance_score', 5, 2)->default(0);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            // Assuming companies table exists, but if not we might just omit company_id foreign key for now if it's not defined in foundation
            // I'll add the index but skip the FK if companies isn't guaranteed. Wait, foundation has companies.
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            $table->foreign('vendor_category_id')->references('id')->on('vendor_categories')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'vendor_code']); // Property level unique code
            $table->index(['property_id', 'vendor_category_id']);
            $table->index(['property_id', 'is_active']);
            $table->index(['property_id', 'name']);
            $table->index(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
