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
        Schema::create('forecast_forecast_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('forecast_id')->index();
            $table->integer('version_number');
            $table->string('status');
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['forecast_id', 'version_number'], 'forecast_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_forecast_versions');
    }
};
