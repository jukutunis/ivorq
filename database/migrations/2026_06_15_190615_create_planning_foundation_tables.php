<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_cycles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('fiscal_year');
            $table->string('cycle_name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('DRAFT'); // DRAFT, OPEN, IN_REVIEW, APPROVED, LOCKED
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->string('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();

            $table->index(['company_id', 'property_id']);
        });

        Schema::create('budget_scenarios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('budget_cycle_id', 26);
            $table->string('name'); // Base, Optimistic, Conservative
            $table->text('description')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            
            $table->timestamps();

            $table->index(['budget_cycle_id']);
            $table->foreign('budget_cycle_id')->references('id')->on('budget_cycles')->onDelete('cascade');
        });

        Schema::create('budget_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('budget_scenario_id', 26);
            $table->integer('version_number');
            $table->string('status')->default('DRAFT'); // DRAFT, APPROVED
            $table->text('change_reason')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->string('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['budget_scenario_id']);
            $table->foreign('budget_scenario_id')->references('id')->on('budget_scenarios')->onDelete('cascade');
        });

        Schema::create('benchmark_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            // No property_id because this is corporate level definition
            $table->string('name'); // e.g., Payroll %, Food Cost %
            $table->string('metric_type'); // PERCENTAGE, RATIO, AMOUNT
            $table->text('description')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();

            $table->timestamps();
        });

        Schema::create('benchmark_targets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('benchmark_template_id', 26);
            $table->string('budget_cycle_id', 26);
            
            $table->decimal('target_value', 15, 4)->nullable();
            $table->decimal('adopted_value', 15, 4)->nullable();
            $table->string('status')->default('ADOPTED'); // ADOPTED, OVERRIDDEN
            $table->text('justification')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();

            $table->timestamps();

            $table->foreign('benchmark_template_id')->references('id')->on('benchmark_templates')->onDelete('cascade');
            $table->foreign('budget_cycle_id')->references('id')->on('budget_cycles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_targets');
        Schema::dropIfExists('benchmark_templates');
        Schema::dropIfExists('budget_versions');
        Schema::dropIfExists('budget_scenarios');
        Schema::dropIfExists('budget_cycles');
    }
};
