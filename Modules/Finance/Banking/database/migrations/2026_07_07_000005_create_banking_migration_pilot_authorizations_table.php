<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_migration_pilot_authorizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('migration_plan_id')->index();
            $table->ulid('manifest_entry_id')->index();
            $table->ulid('target_intake_id')->index();
            $table->string('authorization_scope', 50)->default('account_identity_pilot_only');
            $table->string('status', 30)->index();
            $table->string('correlation_id', 26)->index();
            $table->string('idempotency_key', 64)->index();
            $table->ulid('request_actor_id')->index();
            $table->ulid('review_actor_id')->nullable()->index();
            $table->string('review_outcome', 20)->nullable()->index();
            $table->timestamp('review_timestamp')->nullable();
            $table->string('execution_authority', 50)->default('MIGRATION_EXECUTION_NOT_IMPLEMENTED');
            $table->string('cutover_authority', 50)->default('CUTOVER_NOT_AUTHORIZED');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_migration_pilot_authorizations');
    }
};
