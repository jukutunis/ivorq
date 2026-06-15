<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            
            $table->string('category_name'); // Room Revenue, Food Cost, etc.
            $table->string('category_type'); // REVENUE, EXPENSE, PAYROLL, STATISTICAL
            $table->text('description')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['company_id']);
        });

        Schema::create('budget_gl_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('budget_category_id', 26);
            $table->string('chart_of_account_id', 26);
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('budget_category_id')->references('id')->on('budget_categories')->onDelete('cascade');
            $table->unique(['budget_category_id', 'chart_of_account_id'], 'budget_gl_map_unique');
        });

        Schema::create('budget_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('budget_version_id', 26);
            $table->string('budget_category_id', 26);
            
            $table->string('budgetable_type');
            $table->string('budgetable_id', 26);
            
            $table->integer('period_number');
            $table->decimal('amount', 15, 4);
            
            $table->boolean('is_calculated')->default(false);
            $table->text('override_reason')->nullable();
            $table->string('override_by', 26)->nullable();
            $table->timestamp('override_at')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'budget_version_id']);
            $table->index(['budgetable_type', 'budgetable_id']);
            $table->foreign('budget_version_id')->references('id')->on('budget_versions')->onDelete('cascade');
            $table->foreign('budget_category_id')->references('id')->on('budget_categories')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_entries');
        Schema::dropIfExists('budget_gl_mappings');
        Schema::dropIfExists('budget_categories');
    }
};
