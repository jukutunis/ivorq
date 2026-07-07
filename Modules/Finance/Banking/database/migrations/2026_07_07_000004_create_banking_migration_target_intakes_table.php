<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_migration_target_intakes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('migration_plan_id')->index();
            $table->ulid('manifest_entry_id')->index();
            $table->string('source_domain', 50);
            $table->string('source_model', 80);
            $table->string('target_domain', 50);
            $table->string('target_model', 80);
            $table->ulid('controlled_bank_account_id')->index();
            $table->string('target_identity_hash', 64);
            $table->string('status', 30)->index();
            $table->string('correlation_id', 26)->index();
            $table->ulid('proposal_actor_id')->index();
            $table->ulid('review_actor_id')->nullable()->index();
            $table->string('review_outcome', 20)->nullable()->index();
            $table->timestamp('review_timestamp')->nullable();
            $table->string('execution_authority', 30)->default('UNAVAILABLE');
            $table->string('cutover_authority', 30)->default('CUTOVER_NOT_AUTHORIZED');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_migration_target_intakes');
    }
};
