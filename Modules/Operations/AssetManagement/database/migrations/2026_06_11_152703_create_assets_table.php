<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('department_id')->index()->nullable();
            $table->ulid('location_id')->index()->nullable();
            $table->ulid('asset_category_id')->index();
            $table->ulid('asset_type_id')->index();
            $table->ulid('asset_group_id')->index()->nullable();

            $table->string('name');
            $table->string('asset_number')->nullable();
            $table->string('qr_uri')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('model_number')->nullable();
            $table->string('manufacturer')->nullable();

            $table->string('status'); // AssetStatusEnum
            $table->string('condition'); // AssetConditionEnum
            $table->string('criticality'); // AssetCriticalityEnum
            $table->integer('risk_score')->default(0);

            $table->date('purchase_date')->nullable();
            $table->date('installation_date')->nullable();
            $table->date('commissioning_date')->nullable();
            $table->date('disposal_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('asset_category_id')->references('id')->on('asset_categories');
            $table->foreign('asset_type_id')->references('id')->on('asset_types');
            $table->foreign('asset_group_id')->references('id')->on('asset_groups');

            // Unique constraints
            $table->unique(['property_id', 'asset_number']);
            $table->unique(['property_id', 'qr_uri']);
            
            // Helpful indexes
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'criticality']);
            $table->index(['property_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
