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
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('maintenance_type')->index(); // Time Based, Meter Based, Condition Based
            $table->string('frequency')->nullable(); // Daily, Weekly, Monthly, etc.
            $table->date('next_due_date')->nullable()->index();
            $table->date('last_executed_date')->nullable();
            $table->string('status')->index(); // Active, Inactive
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_plans');
    }
};
