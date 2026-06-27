<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('topic', 100);
            $table->char('source_inventory_transaction_id', 26);
            $table->json('payload');
            $table->string('idempotency_key', 255);
            $table->string('status', 50)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->unique(['source_inventory_transaction_id', 'topic']);
            $table->index('status');
            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
