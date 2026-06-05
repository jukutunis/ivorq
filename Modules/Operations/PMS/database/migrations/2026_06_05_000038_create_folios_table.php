<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);

            // Auto-generated per property (e.g. FOL-00001); handled by observer/service
            $table->string('folio_number', 20);

            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);

            $table->string('status', 30)->default('open'); // FolioStatusEnum: open | closed | void

            // Skeleton financial totals — updated by FolioItem observers
            // Full ledger posting will be wired in the Accounting module integration
            $table->char('currency', 3)->default('MYR');
            $table->decimal('total_charges', 12, 2)->default(0.00);
            $table->decimal('total_payments', 12, 2)->default(0.00);
            $table->decimal('balance', 12, 2)->default(0.00);   // total_charges - total_payments

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->restrictOnDelete();

            $table->unique(['property_id', 'folio_number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'reservation_id']);
            $table->index(['property_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
