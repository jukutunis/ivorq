<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('work_order_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('work_order_id')->index();
            $table->ulid('user_id')->index();
            $table->text('content');
            $table->boolean('is_internal')->default(false); // If true, not visible to guests/watchers
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_comments');
    }
};
