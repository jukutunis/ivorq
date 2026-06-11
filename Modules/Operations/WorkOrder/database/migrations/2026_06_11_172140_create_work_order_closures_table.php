<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('work_order_closures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('work_order_id')->index();
            $table->ulid('closed_by_user_id')->index();
            $table->timestamp('closed_at');
            $table->text('resolution_notes');
            $table->string('root_cause')->nullable();
            $table->boolean('has_signature')->default(false);
            $table->json('snapshot_data')->nullable(); // Immutable snapshot of WO state at closure
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('closed_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_closures');
    }
};
