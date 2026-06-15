<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_assumptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('budget_version_id', 26);
            
            $table->string('metric_type'); // OCCUPANCY, ADR, ROOM_NIGHTS, REVENUE_TARGET
            $table->integer('period_number'); // 1-12 or 1-13
            $table->decimal('value', 15, 4);

            // Future Ready Dimensions
            $table->string('room_type_id', 26)->nullable();
            $table->string('market_segment_id', 26)->nullable();
            $table->string('channel_id', 26)->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'budget_version_id']);
            $table->foreign('budget_version_id')->references('id')->on('budget_versions')->onDelete('cascade');
        });

        Schema::create('operational_assumptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('budget_version_id', 26);
            
            $table->string('metric_type'); // COVERS, AVERAGE_CHECK, FOOD_COST_PERCENT, etc.
            $table->integer('period_number');
            $table->decimal('value', 15, 4);

            // Future Ready Dimensions
            $table->string('department_id', 26)->nullable();
            $table->string('outlet_id', 26)->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'budget_version_id']);
            $table->foreign('budget_version_id')->references('id')->on('budget_versions')->onDelete('cascade');
        });

        Schema::create('labor_assumptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('budget_version_id', 26);
            
            $table->string('metric_type'); // PAYROLL_PERCENT, HEADCOUNT_TARGET, LABOR_HOURS, OVERTIME_PERCENT
            $table->integer('period_number');
            $table->decimal('value', 15, 4);

            // Future Ready Dimensions
            $table->string('department_id', 26)->nullable();
            $table->string('position_id', 26)->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'budget_version_id']);
            $table->foreign('budget_version_id')->references('id')->on('budget_versions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_assumptions');
        Schema::dropIfExists('operational_assumptions');
        Schema::dropIfExists('revenue_assumptions');
    }
};
