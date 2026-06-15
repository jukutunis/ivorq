<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_assumptions', function (Blueprint $table) {
            $table->string('budget_version_id', 26)->nullable()->change();
            $table->string('forecast_version_id', 26)->nullable()->after('budget_version_id');
            $table->index(['property_id', 'forecast_version_id']);
            // We will add the foreign key later after creating forecast_versions table
        });

        Schema::table('operational_assumptions', function (Blueprint $table) {
            $table->string('budget_version_id', 26)->nullable()->change();
            $table->string('forecast_version_id', 26)->nullable()->after('budget_version_id');
            $table->index(['property_id', 'forecast_version_id']);
        });

        Schema::table('labor_assumptions', function (Blueprint $table) {
            $table->string('budget_version_id', 26)->nullable()->change();
            $table->string('forecast_version_id', 26)->nullable()->after('budget_version_id');
            $table->index(['property_id', 'forecast_version_id']);
        });
    }

    public function down(): void
    {
        // ...
    }
};
