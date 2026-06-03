<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone', 50)->nullable();
            $table->string('avatar', 500)->nullable();
            $table->char('department_id', 26)->nullable();
            $table->char('position_id', 26)->nullable();
            $table->char('employee_id', 26)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
            $table->index(['property_id', 'is_active']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
