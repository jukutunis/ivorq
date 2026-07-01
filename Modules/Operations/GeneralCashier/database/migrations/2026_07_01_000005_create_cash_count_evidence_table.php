<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_count_evidence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('observed_amount', 19, 2);
            $table->date('observed_count_date')->index();
            $table->string('source_reference', 120);
            $table->ulid('counted_by')->index();
            $table->ulid('recorded_by')->index();
            $table->timestamp('recorded_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['property_id', 'operational_gl_account_id', 'currency_code', 'observed_count_date', 'source_reference'],
                'cash_count_evidence_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_evidence');
    }
};
