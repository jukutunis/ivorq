<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_migration_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('source_domain', 50);
            $table->string('target_domain', 50);
            $table->string('status', 30)->index();
            $table->string('correlation_id', 26)->index();
            $table->string('idempotency_key', 64)->unique();
            $table->json('dry_run_metadata')->nullable();
            $table->string('execution_authority', 30)->default('UNAVAILABLE');
            $table->string('cutover_authority', 30)->default('CUTOVER_NOT_AUTHORIZED');
            $table->ulid('created_actor_id')->index();
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_migration_plans');
    }
};
