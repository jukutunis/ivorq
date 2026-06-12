<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_and_found_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('reference_number')->unique();
            $table->char('room_id', 26)->nullable();
            $table->string('location_description')->nullable();
            $table->char('found_by_user_id', 26)->nullable();
            $table->string('category_id', 50)->nullable(); // valuable, clothing, electronics
            $table->string('status', 30)->default('reported'); // reported, secured, claimed, disposed, shipped
            $table->text('description');
            $table->json('chain_of_custody')->nullable(); // immutable ledger
            $table->char('supervisor_approval_id', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_and_found_items');
    }
};