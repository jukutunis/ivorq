<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_batches', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('batch_number')->unique();
            $table->char('vendor_id', 26)->nullable();
            $table->string('status', 30)->default('outgoing'); // outgoing, washing, received, verified
            $table->integer('total_items_sent')->default(0);
            $table->integer('total_items_received')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_batches');
    }
};