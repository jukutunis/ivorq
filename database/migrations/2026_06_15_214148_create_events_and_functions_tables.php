<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('opportunity_id', 26);
            $table->string('event_name');
            
            $table->string('status');
            $table->string('event_type');
            
            // Calendar Readiness
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->dateTime('setup_start')->nullable();
            $table->dateTime('breakdown_end')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('opportunity_id')->references('id')->on('opportunities')->onDelete('cascade');
        });

        Schema::create('event_functions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('event_id', 26);
            $table->string('function_name');
            
            $table->string('status');
            
            // Calendar Readiness
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->dateTime('setup_start')->nullable();
            $table->dateTime('breakdown_end')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_functions');
        Schema::dropIfExists('events');
    }
};
