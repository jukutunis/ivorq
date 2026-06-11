<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            $table->ulid('vendor_id')->index(); // ID of global vendor table if exists, otherwise text
            $table->string('vendor_role'); // Manufacturer, Supplier, ServiceVendor
            
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->unique(['property_id', 'asset_id', 'vendor_id', 'vendor_role'], 'asset_vendor_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_vendors');
    }
};
