<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('forecast_forecast_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('forecast_version_id')->index();
            $table->ulid('department_id')->nullable()->index();
            $table->ulid('account_id')->index();
            $table->integer('period_month');
            $table->decimal('amount', 19, 4)->default(0);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['forecast_version_id', 'department_id', 'account_id', 'period_month'], 'forecast_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_forecast_lines');
    }
};
