<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_isolations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('permit_id', 26);
            $table->char('asset_id', 26);
            $table->string('isolation_type');
            $table->boolean('is_isolated')->default(false);
            $table->timestamps();
        });
    }
};
