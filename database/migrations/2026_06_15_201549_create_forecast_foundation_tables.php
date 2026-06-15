<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            
            $table->string('forecast_name');
            $table->string('forecast_type'); // ROLLING, REFORECAST
            $table->string('forecast_source_type'); // MANUAL, BUDGET_SEED, etc.
            
            $table->string('base_budget_version_id', 26)->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['property_id']);
            $table->foreign('base_budget_version_id')->references('id')->on('budget_versions')->onDelete('set null');
        });

        Schema::create('forecast_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('forecast_id', 26);
            
            $table->integer('version_number');
            $table->string('status'); // DRAFT, LOCKED
            
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            
            $table->string('accuracy_status')->default('PENDING'); // PENDING, CALCULATED
            $table->timestamp('accuracy_calculated_at')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('forecast_id')->references('id')->on('forecasts')->onDelete('cascade');
        });

        // Add foreign key to assumption tables now that forecast_versions exists
        Schema::table('revenue_assumptions', function (Blueprint $table) {
            $table->foreign('forecast_version_id')->references('id')->on('forecast_versions')->onDelete('cascade');
        });

        Schema::table('operational_assumptions', function (Blueprint $table) {
            $table->foreign('forecast_version_id')->references('id')->on('forecast_versions')->onDelete('cascade');
        });

        Schema::table('labor_assumptions', function (Blueprint $table) {
            $table->foreign('forecast_version_id')->references('id')->on('forecast_versions')->onDelete('cascade');
        });

        Schema::create('forecast_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('forecast_version_id', 26);
            $table->string('budget_category_id', 26);
            
            $table->string('forecastable_type');
            $table->string('forecastable_id', 26);
            
            $table->integer('period_number');
            $table->decimal('amount', 15, 4);
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->index(['forecast_version_id']);
            $table->index(['forecastable_type', 'forecastable_id']);
            
            $table->foreign('forecast_version_id')->references('id')->on('forecast_versions')->onDelete('cascade');
            $table->foreign('budget_category_id')->references('id')->on('budget_categories')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_entries');
        Schema::table('labor_assumptions', function (Blueprint $table) {
            $table->dropForeign(['forecast_version_id']);
        });
        Schema::table('operational_assumptions', function (Blueprint $table) {
            $table->dropForeign(['forecast_version_id']);
        });
        Schema::table('revenue_assumptions', function (Blueprint $table) {
            $table->dropForeign(['forecast_version_id']);
        });
        Schema::dropIfExists('forecast_versions');
        Schema::dropIfExists('forecasts');
    }
};
