<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_hierarchies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('ancestor_id')->index();
            $table->ulid('descendant_id')->index();
            $table->integer('depth');
            $table->timestamps();

            $table->foreign('ancestor_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('descendant_id')->references('id')->on('assets')->onDelete('cascade');

            $table->unique(['property_id', 'ancestor_id', 'descendant_id'], 'asset_hierarchy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_hierarchies');
    }
};
