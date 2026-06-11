<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_to_works', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('work_order_id', 26)->nullable();
            $table->char('company_id', 26)->nullable();
            $table->string('permit_type');
            $table->string('status')->default('draft');
            $table->boolean('is_emergency')->default(false);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
