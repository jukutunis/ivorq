<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_payment_transactions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('payment_number', 32);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2);
            $table->string('tender_type', 20);
            $table->char('cashier_session_id', 26);
            $table->string('lifecycle_status', 32);
            $table->string('recording_idempotency_key', 96);
            $table->timestamp('recorded_at');
            $table->char('recorded_by', 26);
            $table->json('source_snapshot');
            $table->timestamps();
            $table->char('created_by', 26);
            $table->char('updated_by', 26)->nullable();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->restrictOnDelete();
            $table->foreign('cashier_session_id')->references('id')->on('cashier_sessions')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'id'], 'guest_payments_property_id_unique');
            $table->unique(['property_id', 'payment_number'], 'guest_payments_property_number_unique');
            $table->unique(['property_id', 'recording_idempotency_key'], 'guest_payments_property_idem_unique');
            $table->index(['property_id', 'reservation_id'], 'guest_payments_property_reservation_index');
            $table->index(['property_id', 'cashier_session_id'], 'guest_payments_property_session_index');
        });

        DB::statement("ALTER TABLE guest_payment_transactions ADD CONSTRAINT guest_payments_amount_positive_check CHECK (amount > 0)");
        DB::statement("ALTER TABLE guest_payment_transactions ADD CONSTRAINT guest_payments_tender_check CHECK (tender_type IN ('CASH'))");
        DB::statement("ALTER TABLE guest_payment_transactions ADD CONSTRAINT guest_payments_status_check CHECK (lifecycle_status IN ('RECORDED','PARTIALLY_ALLOCATED','FULLY_ALLOCATED','VOIDED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_payment_transactions');
    }
};
