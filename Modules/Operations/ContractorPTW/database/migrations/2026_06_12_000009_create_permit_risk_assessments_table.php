<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_risk_assessments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('permit_id', 26);
            $table->text('hazards');
            $table->text('control_measures');
            $table->string('initial_risk');
            $table->string('residual_risk');
            $table->timestamps();
        });
    }
};
