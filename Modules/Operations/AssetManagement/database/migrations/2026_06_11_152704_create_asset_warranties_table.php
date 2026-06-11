<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_warranties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            $table->ulid('vendor_id')->nullable()->index(); // Will reference vendors or asset_vendors

            $table->string('coverage_type')->nullable(); // Parts, Labor, Full
            $table->date('start_date');
            $table->date('end_date');
            $table->text('terms')->nullable();
            
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->index(['property_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_warranties');
    }
};
