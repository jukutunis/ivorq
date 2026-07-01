<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_bank_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('controlled_bank_account_id')->index();
            $table->ulid('property_id')->index();
            $table->string('source_reference', 120);
            $table->string('external_reference', 120);
            $table->date('statement_date')->index();
            $table->string('direction', 20);
            $table->decimal('amount', 19, 2);
            $table->string('currency_code', 3);
            $table->string('vendor_reference', 120)->nullable();
            $table->ulid('recorded_by')->index();
            $table->timestamp('recorded_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['controlled_bank_account_id', 'external_reference'],
                'controlled_bank_statement_lines_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_bank_statement_lines');
    }
};
