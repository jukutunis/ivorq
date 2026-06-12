<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_credits', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('attendant_id', 26);
            $table->date('work_date');
            $table->decimal('assigned_credits', 5, 2)->default(0);
            $table->decimal('completed_credits', 5, 2)->default(0);
            $table->timestamps();
            
            $table->unique(['property_id', 'attendant_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_credits');
    }
};