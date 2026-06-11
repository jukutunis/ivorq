<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_audits', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('permit_id', 26);
            $table->char('property_id', 26);
            $table->string('action');
            $table->json('snapshot');
            $table->string('hash');
            $table->timestamps();
        });
    }
};
