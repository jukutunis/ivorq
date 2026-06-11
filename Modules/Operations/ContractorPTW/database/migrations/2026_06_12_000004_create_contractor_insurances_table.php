<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_insurances', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('company_id', 26);
            $table->string('policy_number');
            $table->string('provider_name');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
