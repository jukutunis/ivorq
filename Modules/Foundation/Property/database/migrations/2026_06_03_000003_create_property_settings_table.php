<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_settings', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('group', 100)->default('general');
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_public')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->unique(['property_id', 'group', 'key']);
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_settings');
    }
};
