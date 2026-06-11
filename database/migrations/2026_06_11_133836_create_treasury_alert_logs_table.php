<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('treasury_alert_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('alert_type');
            $table->string('severity');
            $table->text('message');
            $table->json('context_data')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_alert_logs');
    }
};
