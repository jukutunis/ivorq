<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('maintenance_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('maintenance_plan_id')->index();
            $table->ulid('asset_id')->index();
            $table->string('status')->index(); // Pending, In Progress, Completed, Cancelled
            $table->date('scheduled_date')->index();
            $table->date('executed_date')->nullable();
            $table->ulid('executed_by')->nullable();
            $table->json('checklist_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_executions');
    }
};
