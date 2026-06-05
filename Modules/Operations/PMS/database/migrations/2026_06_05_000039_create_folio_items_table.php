<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_items', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('folio_id', 26);

            // FolioItemTypeEnum: room_charge | tax | service_charge | adjustment | payment | deposit | other
            $table->string('item_type', 30);

            $table->string('description', 255);
            $table->decimal('quantity', 8, 2)->default(1.00);
            $table->decimal('amount', 12, 2);  // positive = charge; negative = credit/payment

            $table->boolean('is_void')->default(false);

            // Audit trail for posting
            $table->dateTime('posted_at');
            $table->char('posted_by', 26)->nullable();

            // Soft audit columns (no full updated_by needed for immutable ledger lines)
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('folio_id')->references('id')->on('folios')->restrictOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['property_id', 'folio_id']);
            $table->index(['property_id', 'item_type']);
            $table->index(['folio_id', 'item_type', 'is_void']);
            $table->index(['property_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_items');
    }
};
