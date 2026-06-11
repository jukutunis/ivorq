<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_access_passes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('worker_profile_id', 26);
            $table->char('property_id', 26);
            $table->timestamp('check_in_time');
            $table->timestamp('check_out_time')->nullable();
            $table->string('qr_code')->nullable();
            $table->timestamps();
        });
    }
};
