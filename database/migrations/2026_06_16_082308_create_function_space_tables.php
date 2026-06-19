<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master Data Entities
        Schema::create('venue_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('setup_styles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('venue_features', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Physical Inventory Entities
        Schema::create('venues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->onDelete('cascade');
            $table->foreignUlid('venue_category_id')->nullable()->constrained('venue_categories')->nullOnDelete();
            $table->ulid('parent_venue_id')->nullable();
            
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('ACTIVE');
            $table->integer('default_turnaround_minutes')->default(60);
            
            $table->decimal('square_meter', 10, 2)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('ceiling_height', 10, 2)->nullable();
            
            $table->boolean('is_active')->default(true);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'code']);
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->foreign('parent_venue_id')->references('id')->on('venues')->nullOnDelete();
        });

        Schema::create('venue_feature_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('venue_id')->constrained('venues')->onDelete('cascade');
            $table->foreignUlid('venue_feature_id')->constrained('venue_features')->onDelete('cascade');
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'venue_feature_id']);
        });

        Schema::create('venue_capacities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('venue_id')->constrained('venues')->onDelete('cascade');
            $table->foreignUlid('setup_style_id')->constrained('setup_styles')->onDelete('cascade');
            $table->integer('maximum_capacity');
            $table->integer('optimal_capacity')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'setup_style_id']);
        });

        Schema::create('venue_combinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('parent_venue_id')->constrained('venues')->onDelete('cascade');
            $table->foreignUlid('child_venue_id')->constrained('venues')->onDelete('cascade');
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['parent_venue_id', 'child_venue_id']);
        });

        // 3. Operational Entities
        Schema::create('function_space_bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('venue_id')->constrained('venues')->onDelete('cascade');
            $table->foreignUlid('event_function_id')->constrained('event_functions')->onDelete('cascade');
            
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('status'); // TENTATIVE, DEFINITE, IN_HOUSE, COMPLETED, CANCELLED
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('venue_maintenance_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('venue_id')->constrained('venues')->onDelete('cascade');
            
            $table->string('maintenance_type'); // PREVENTIVE, OUT_OF_ORDER
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->text('reason')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_maintenance_blocks');
        Schema::dropIfExists('function_space_bookings');
        Schema::dropIfExists('venue_combinations');
        Schema::dropIfExists('venue_capacities');
        Schema::dropIfExists('venue_feature_assignments');
        Schema::dropIfExists('venues');
        Schema::dropIfExists('venue_features');
        Schema::dropIfExists('setup_styles');
        Schema::dropIfExists('venue_categories');
    }
};
