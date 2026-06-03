<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->char('property_id', 26)->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_type', 50)->nullable();
            $table->string('platform', 50)->nullable();
            $table->text('push_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
