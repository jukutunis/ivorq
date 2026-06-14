<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_operational_identity_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->string('operational_identity');
            $table->ulid('cost_center_id')->nullable();
            $table->ulid('account_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('override_account_code')->nullable();
            $table->string('override_account_name')->nullable();

            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['property_id', 'operational_identity'], 'idx_property_identity');
            $table->index(['property_id', 'operational_identity', 'cost_center_id'], 'idx_property_identity_cost_center');
            $table->index(['property_id', 'is_active'], 'idx_property_is_active');
            
            $table->foreign('account_id')->references('id')->on('gl_accounts')->onDelete('restrict');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('cascade');
            $table->foreign('cost_center_id')->references('id')->on('departments')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_operational_identity_mappings');
    }
};
