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
        Schema::create('maintenance_exceptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            $table->ulid('maintenance_plan_id')->nullable()->index();
            $table->ulid('maintenance_execution_id')->nullable()->index();
            $table->ulid('maintenance_checklist_id')->nullable()->index();
            $table->string('exception_type')->index();
            $table->text('description')->nullable();
            $table->string('status')->index(); // Open, Resolved, ConvertedToWorkOrder
            $table->ulid('reported_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_exceptions');
    }
};
