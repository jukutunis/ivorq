<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('task_id', 26);
            $table->char('property_id', 26);
            $table->char('user_id', 26);
            
            $table->timestamps();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            
            $table->unique(['task_id', 'user_id'], 'task_watchers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_watchers');
    }
};
