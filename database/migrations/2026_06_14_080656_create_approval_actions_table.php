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
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignUlid('approval_step_id')->constrained('approval_steps')->cascadeOnDelete();
            
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('delegated_from_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('action_type');
            $table->text('notes')->nullable();
            
            $table->ipAddress('ip_address')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
    }
};
