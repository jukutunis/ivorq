<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('work_order_slas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('work_order_id')->index();
            $table->timestamp('target_response_at')->nullable();
            $table->timestamp('actual_response_at')->nullable();
            $table->boolean('is_response_breached')->default(false);
            $table->timestamp('target_resolution_at')->nullable();
            $table->timestamp('actual_resolution_at')->nullable();
            $table->boolean('is_resolution_breached')->default(false);
            $table->integer('total_pause_minutes')->default(0);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_slas');
    }
};
