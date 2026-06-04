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
            $table->char('property_id', 26);
            $table->char('checklist_id', 26);
            $table->string('item_text', 500);
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->boolean('is_required')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('checklist_id')->references('id')->on('cleaning_checklists')->restrictOnDelete();

            $table->index(['checklist_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
