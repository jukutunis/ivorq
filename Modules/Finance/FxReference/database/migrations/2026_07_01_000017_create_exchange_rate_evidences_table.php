<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_evidences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);
            $table->decimal('rate', 20, 8);
            $table->string('quote_convention', 80);
            $table->date('effective_date');
            $table->string('source_reference', 120);
            $table->string('status', 20)->index();
            $table->ulid('recorded_by')->index();
            $table->timestamp('recorded_at');
            $table->ulid('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('rejected_by')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['property_id', 'base_currency', 'quote_currency', 'quote_convention', 'effective_date', 'source_reference'],
                'exchange_rate_evidence_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_evidences');
    }
};
