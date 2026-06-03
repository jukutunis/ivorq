<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->text('description');
            $table->string('subject_type', 255)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->string('causer_type', 255)->nullable();
            $table->char('causer_id', 26)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('property_id');
            $table->index('user_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
