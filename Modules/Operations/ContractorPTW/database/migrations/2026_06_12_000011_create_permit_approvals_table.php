<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_approvals', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('permit_id', 26);
            $table->char('approver_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('approved_at')->nullable();
            $table->string('signature_hash')->nullable();
            $table->timestamps();
        });
    }
};
