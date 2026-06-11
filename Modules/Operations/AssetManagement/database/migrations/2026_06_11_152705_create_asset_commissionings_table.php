<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_commissionings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('asset_id')->index();
            
            $table->string('status'); // AssetCommissioningStatusEnum
            
            $table->date('acceptance_test_date')->nullable();
            $table->ulid('vendor_signoff_user_id')->nullable();
            $table->ulid('engineer_signoff_user_id')->nullable();
            
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_commissionings');
    }
};
