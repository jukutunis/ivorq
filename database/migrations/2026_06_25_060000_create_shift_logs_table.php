<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('shift_id', 26)->nullable();
            $table->char('department_id', 26)->nullable();
            $table->string('area')->nullable();
            
            $table->string('subject');
            $table->text('content');
            $table->string('category')->index();
            $table->string('priority')->index();
            $table->string('status')->index();
            
            $table->boolean('requires_follow_up')->default(false)->index();
            
            $table->char('created_by', 26);
            $table->char('submitted_by', 26)->nullable();
            $table->dateTime('submitted_at')->nullable();
            
            $table->char('acknowledged_by', 26)->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            
            $table->timestamps();

            // Indexes for property/status and follow-up queries
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'requires_follow_up']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_logs');
    }
};
