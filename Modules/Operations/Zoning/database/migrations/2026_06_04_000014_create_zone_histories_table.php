<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_histories', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26)->nullable();
            $table->char('zone_id', 26)->nullable();
            $table->string('action', 100);
            $table->char('performed_by', 26)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // zone_id is nullable — history records survive zone deletion
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();

            // performed_by is nullable — history records survive user deletion
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('property_id');
            $table->index('zone_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_histories');
    }
};
