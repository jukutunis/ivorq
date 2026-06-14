<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('user_id', 26);
            
            $table->string('type', 100);
            $table->string('priority', 30)->default('normal');
            
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable(); // action_url, meta info
            
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            
            $table->timestamps();
            
            // SoftDeletes might not be strictly needed for notifications, but useful for audit/history
            $table->softDeletes();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->char('deleted_by', 26)->nullable();
            
            $table->index(['property_id', 'user_id', 'is_read']);
            $table->index(['property_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
