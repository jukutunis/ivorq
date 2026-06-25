<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('department_id', 26);
            
            $table->string('subject');
            $table->text('content');
            $table->string('category')->index();
            $table->string('priority')->index();
            $table->string('status')->index();
            
            $table->boolean('requires_follow_up')->default(false)->index();
            
            $table->char('created_by', 26);
            $table->char('submitted_by', 26)->nullable();
            $table->dateTime('submitted_at')->nullable();
            
            $table->timestamps();

            // Indexes for property/status and follow-up / creator queries
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'requires_follow_up']);
            $table->index(['property_id', 'created_by']);
            $table->index(['property_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_entries');
    }
};
