<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26);
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 20)->unique();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('timezone', 100)->default('UTC');
            $table->char('currency', 3)->default('USD');
            $table->string('logo', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->index('company_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
