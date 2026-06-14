<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('task_id', 26);
            $table->char('property_id', 26);
            $table->char('user_id', 26);
            $table->text('comment');
            $table->boolean('is_internal')->default(true);
            
            $table->timestamps();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            
            $table->index(['task_id', 'is_internal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
