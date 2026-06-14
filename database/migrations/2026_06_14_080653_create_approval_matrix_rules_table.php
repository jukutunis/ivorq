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
        Schema::create('approval_matrix_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('module')->nullable();
            $table->string('document_type')->nullable();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            
            $table->string('assignee_type');
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignUlid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_matrix_rules');
    }
};
