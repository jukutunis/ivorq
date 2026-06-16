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
        Schema::create('event_execution_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignUlid('property_id')->nullable()->constrained('properties')->onDelete('cascade');
            $table->string('name');
            $table->string('category');
            $table->string('status');
            $table->integer('revision_number')->default(0);
            $table->ulid('previous_template_id')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('operational_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_execution_template_id', 'op_pkg_eet_id_foreign')->constrained('event_execution_templates')->onDelete('cascade');
            $table->string('name');
            $table->string('package_type');
            $table->string('revenue_classification');
            $table->boolean('is_active')->default(true);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('venue_setup_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id')->constrained('operational_packages')->onDelete('cascade');
            $table->string('venue_type');
            $table->string('setup_style')->nullable();
            $table->integer('expected_capacity')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fb_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id')->constrained('operational_packages')->onDelete('cascade');
            $table->string('meal_type')->nullable();
            $table->text('menu_description')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('av_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id')->constrained('operational_packages')->onDelete('cascade');
            $table->text('equipment_required')->nullable();
            $table->boolean('technician_required')->default(false);
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id')->constrained('operational_packages')->onDelete('cascade');
            $table->text('deposit_schedule')->nullable();
            $table->text('cancellation_policy')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('special_request_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id', 'sp_req_op_pkg_id_foreign')->constrained('operational_packages')->onDelete('cascade');
            $table->text('request_details')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('task_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id')->constrained('operational_packages')->onDelete('cascade');
            $table->string('task_name');
            $table->string('priority')->default('MEDIUM');
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->integer('due_offset_minutes')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('staffing_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('operational_package_id')->constrained('operational_packages')->onDelete('cascade');
            $table->string('role_name');
            $table->foreignUlid('department_id', 'stf_sec_dept_id_foreign')->constrained('departments')->onDelete('cascade');
            $table->integer('headcount')->default(1);
            $table->decimal('shift_duration_hours', 5, 2)->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staffing_sections');
        Schema::dropIfExists('task_sections');
        Schema::dropIfExists('special_request_sections');
        Schema::dropIfExists('billing_sections');
        Schema::dropIfExists('av_sections');
        Schema::dropIfExists('fb_sections');
        Schema::dropIfExists('venue_setup_sections');
        Schema::dropIfExists('operational_packages');
        Schema::dropIfExists('event_execution_templates');
    }
};
