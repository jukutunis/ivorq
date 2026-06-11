<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_inductions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('worker_profile_id', 26);
            $table->char('property_id', 26);
            $table->timestamp('induction_date');
            $table->timestamp('expiry_date');
            $table->timestamps();
        });
    }
};
