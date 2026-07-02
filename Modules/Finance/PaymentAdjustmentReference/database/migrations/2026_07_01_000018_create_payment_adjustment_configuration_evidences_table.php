<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_adjustment_configuration_evidences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('adjustment_type', 20);
            $table->string('policy_type', 20);
            $table->decimal('policy_value', 20, 8);
            $table->string('policy_currency', 10)->nullable();
            $table->ulid('adjustment_account_mapping_id')->index();
            $table->json('mapping_snapshot');
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
                [
                    'property_id',
                    'adjustment_type',
                    'policy_type',
                    'policy_currency',
                    'adjustment_account_mapping_id',
                    'effective_date',
                    'source_reference',
                ],
                'payment_adjustment_config_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_adjustment_configuration_evidences');
    }
};
