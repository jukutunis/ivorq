<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_certifications', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('worker_global_id', 26);
            $table->string('certification_name');
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });
    }
};
