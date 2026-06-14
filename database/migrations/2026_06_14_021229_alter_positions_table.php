<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropForeign(['department_id']);
            $table->dropIndex(['property_id', 'department_id']);
            $table->dropColumn(['property_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->char('property_id', 26)->nullable();
            $table->char('department_id', 26)->nullable();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->index(['property_id', 'department_id']);
        });
    }
};
