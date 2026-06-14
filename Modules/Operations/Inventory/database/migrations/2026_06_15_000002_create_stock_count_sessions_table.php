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
        Schema::create('stock_count_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('session_number')->unique();
            $table->string('type'); // FULL_COUNT, CYCLE_COUNT, SPOT_COUNT
            $table->string('scope'); // PROPERTY, ZONE, LOCATION, ITEM_GROUP
            $table->string('status')->default('draft');
            
            $table->ulid('location_id')->nullable()->index();
            $table->ulid('zone_id')->nullable()->index();
            $table->ulid('category_id')->nullable()->index();

            $table->ulid('created_by')->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->ulid('assigned_to')->nullable()->index();
            $table->ulid('counted_by')->nullable()->index();
            $table->ulid('submitted_by')->nullable()->index();
            $table->ulid('approved_by')->nullable()->index();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_count_sessions');
    }
};
