<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_document_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('document_type', 20);
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(
                ['property_id', 'document_type', 'sequence_year'],
                'purchasing_document_sequences_scope_unique'
            );
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('creation_idempotency_key', 120)->nullable()->after('request_no');
            $table->char('source_fingerprint', 64)->nullable()->after('creation_idempotency_key');
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unique(
                ['property_id', 'creation_idempotency_key'],
                'purchase_requests_property_idempotency_unique'
            );
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('issue_date')->nullable()->change();
            $table->foreignUlid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->string('issue_idempotency_key', 120)->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unique(
                ['property_id', 'issue_idempotency_key'],
                'purchase_orders_property_issue_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_property_issue_key_unique');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'rejected_at',
                'rejection_reason',
                'issued_at',
                'issue_idempotency_key',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropUnique('purchase_requests_property_idempotency_unique');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'creation_idempotency_key',
                'source_fingerprint',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });

        Schema::dropIfExists('purchasing_document_sequences');
    }
};
