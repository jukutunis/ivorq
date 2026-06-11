<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_relationships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('source_asset_id')->index();
            $table->ulid('target_asset_id')->index();
            $table->string('relationship_type'); // AssetRelationshipTypeEnum
            $table->timestamps();

            $table->foreign('source_asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('target_asset_id')->references('id')->on('assets')->onDelete('cascade');

            $table->unique(['property_id', 'source_asset_id', 'target_asset_id', 'relationship_type'], 'asset_relationship_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_relationships');
    }
};
