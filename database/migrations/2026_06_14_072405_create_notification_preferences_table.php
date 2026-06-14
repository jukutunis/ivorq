<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('user_id', 26)->nullable(); // Null means property-level default
            $table->char('department_id', 26)->nullable(); // Optional department defaults
            
            $table->string('notification_type', 100); // e.g. TaskAssigned
            
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('push_enabled')->default(false);
            
            // Allow muting a specific type
            $table->boolean('is_muted')->default(false);
            
            $table->timestamps();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            
            $table->index(['property_id', 'user_id', 'notification_type'], 'notif_prefs_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
