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
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->cascadeOnDelete();
            
            $table->ulidMorphs('approvable');
            $table->foreignUlid('workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->foreignUlid('current_step_id')->nullable()->constrained('approval_steps')->nullOnDelete();
            $table->foreignUlid('requester_id')->constrained('users')->cascadeOnDelete();
            
            $table->string('status')->default('Draft');
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('notes')->nullable();
            
            $table->json('workflow_snapshot')->nullable();
            $table->json('step_snapshot')->nullable();
            $table->json('matrix_snapshot')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
