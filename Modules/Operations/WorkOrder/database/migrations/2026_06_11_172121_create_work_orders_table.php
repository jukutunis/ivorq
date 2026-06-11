<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('wo_number')->index();
            $table->ulid('asset_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->index();
            $table->string('priority')->index();
            $table->string('type')->index();
            $table->string('source_type')->nullable()->index();
            $table->ulid('source_id')->nullable()->index();
            $table->boolean('has_guest_impact')->default(false);
            $table->integer('priority_score')->default(0)->index();
            $table->timestamp('target_resolution_at')->nullable()->index();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_orders');
    }
};
