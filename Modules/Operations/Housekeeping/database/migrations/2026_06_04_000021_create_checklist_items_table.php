<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable();
            $table->char('checklist_id', 26);
            $table->string('item_text')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('weight')->default(1);
            $table->integer('sort_order')->default(0);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};