<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_bank_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('operational_gl_account_id')->index();
            $table->string('bank_name', 120);
            $table->string('account_name', 120);
            $table->string('external_account_reference', 120);
            $table->string('currency_code', 3);
            $table->boolean('is_active')->default(true)->index();
            $table->string('source_reference', 120);
            $table->ulid('registered_by')->index();
            $table->timestamp('registered_at');
            $table->string('source_identity_hash', 64)->unique();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['property_id', 'operational_gl_account_id'], 'controlled_bank_accounts_property_gl_unique');
            $table->unique(['property_id', 'external_account_reference'], 'controlled_bank_accounts_property_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_bank_accounts');
    }
};
