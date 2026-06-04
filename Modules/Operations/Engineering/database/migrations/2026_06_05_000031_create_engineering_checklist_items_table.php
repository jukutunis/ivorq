<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Follows Housekeeping checklist_items pattern exactly:
        // no soft delete, restrictOnDelete FK on parent checklist.
        Schema::create('engineering_checklist_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('engineering_checklist_id', 26);
            $table->string('item_text', 500);
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->boolean('is_required')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('engineering_checklist_id')
                ->references('id')->on('engineering_checklists')
                ->restrictOnDelete();

            $table->index(['engineering_checklist_id', 'sort_order']);
            $table->index(['property_id', 'engineering_checklist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_checklist_items');
    }
};
