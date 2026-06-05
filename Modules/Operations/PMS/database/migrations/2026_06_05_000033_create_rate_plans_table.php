<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);

            // Skeleton fields — full pricing engine is a future integration point
            $table->string('rate_code', 20);
            $table->string('rate_name', 255);
            $table->string('plan_type', 30)->default('nightly'); // RatePlanTypeEnum
            $table->decimal('base_rate', 10, 2);
            $table->char('currency', 3)->default('MYR');         // ISO 4217
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();

            $table->unique(['property_id', 'rate_code']);
            $table->index(['property_id', 'is_active']);
            $table->index(['property_id', 'plan_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plans');
    }
};
