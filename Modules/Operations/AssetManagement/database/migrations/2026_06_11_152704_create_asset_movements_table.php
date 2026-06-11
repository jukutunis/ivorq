<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            $table->string('movement_type'); // AssetMovementTypeEnum
            
            $table->ulid('from_location_id')->nullable()->index();
            $table->ulid('to_location_id')->nullable()->index();
            $table->ulid('from_department_id')->nullable()->index();
            $table->ulid('to_department_id')->nullable()->index();
            $table->ulid('user_id')->nullable()->index();

            $table->date('movement_date');
            $table->text('reason')->nullable();
            
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_movements');
    }
};
