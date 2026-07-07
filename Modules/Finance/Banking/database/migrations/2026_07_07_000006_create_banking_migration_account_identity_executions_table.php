<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_migration_account_identity_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('migration_plan_id')->index();
            $table->ulid('manifest_entry_id')->index();
            $table->ulid('target_intake_id')->index();
            $table->ulid('pilot_authorization_id')->index();
            $table->string('source_domain', 50);
            $table->string('source_model', 120);
            $table->ulid('source_ulid')->index();
            $table->ulid('source_property_id')->index();
            $table->string('source_identity_hash', 64);
            $table->string('source_snapshot_hash', 64);
            $table->string('target_domain', 50);
            $table->string('target_model', 120);
            $table->ulid('target_ulid')->index();
            $table->ulid('target_property_id')->index();
            $table->string('target_identity_hash', 64);
            $table->string('outcome', 60)->index();
            $table->ulid('execution_actor_id')->index();
            $table->ulid('pilot_auth_reviewer_id')->index();
            $table->string('correlation_id', 26)->index();
            $table->string('idempotency_key', 64);
            $table->json('confirmation_evidence')->nullable();
            $table->timestamp('executed_at');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['property_id', 'source_domain', 'source_model', 'source_ulid'], 'exec_identity_unique_source_per_property');
            $table->unique(['property_id', 'target_domain', 'target_model', 'target_ulid'], 'exec_identity_unique_target_per_property');
            $table->unique(['property_id', 'idempotency_key'], 'exec_identity_unique_idempotency_per_property');

            $table->foreign('migration_plan_id')->references('id')->on('banking_migration_plans');
            $table->foreign('manifest_entry_id')->references('id')->on('banking_migration_manifest_entries');
            $table->foreign('target_intake_id')->references('id')->on('banking_migration_target_intakes');
            $table->foreign('pilot_authorization_id')->references('id')->on('banking_migration_pilot_authorizations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_migration_account_identity_executions');
    }
};
