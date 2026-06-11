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
        Schema::create('maintenance_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            $table->ulid('maintenance_plan_id')->nullable()->index();
            $table->ulid('maintenance_execution_id')->index();
            $table->string('status');
            $table->text('notes')->nullable();
            $table->ulid('executed_by')->nullable();
            $table->date('executed_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_histories');
    }
};
