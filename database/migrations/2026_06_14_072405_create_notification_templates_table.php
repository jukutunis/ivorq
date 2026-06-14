<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable(); // Null = Global system template
            
            $table->string('notification_type', 100);
            $table->string('channel', 50); // in_app, email, push
            $table->string('locale', 10)->default('en');
            
            $table->string('subject')->nullable();
            $table->text('body_template');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->char('deleted_by', 26)->nullable();
            
            $table->index(['property_id', 'notification_type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
