<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('task_id', 26);
            $table->char('property_id', 26);
            $table->char('user_id', 26);
            $table->string('file_path');
            $table->string('file_type', 50)->nullable();
            
            $table->timestamps();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
