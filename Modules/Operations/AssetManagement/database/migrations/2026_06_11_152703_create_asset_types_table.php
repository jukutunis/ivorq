<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_category_id')->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('asset_category_id')->references('id')->on('asset_categories')->onDelete('cascade');
            $table->unique(['property_id', 'name']);
            $table->index(['property_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_types');
    }
};
