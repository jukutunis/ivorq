<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->char('parent_id', 26)->nullable()->after('property_id');
            $table->string('type', 50)->nullable()->after('code'); // Operational, Administrative, Support, Revenue
            $table->string('cost_center_code', 50)->nullable()->after('type');

            $table->foreign('parent_id')->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'type', 'cost_center_code']);
        });
    }
};
