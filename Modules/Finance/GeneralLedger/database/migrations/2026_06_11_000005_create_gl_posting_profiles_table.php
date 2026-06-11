<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_posting_profiles', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('module');
            $table->string('event');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['property_id', 'module', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_posting_profiles');
    }
};
