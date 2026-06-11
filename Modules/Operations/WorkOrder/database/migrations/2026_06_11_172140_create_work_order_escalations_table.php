<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('work_order_escalations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('work_order_id')->index();
            $table->ulid('escalated_to_user_id')->nullable()->index();
            $table->ulid('escalated_to_department_id')->nullable()->index();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('escalated_to_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('escalated_to_department_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_escalations');
    }
};
