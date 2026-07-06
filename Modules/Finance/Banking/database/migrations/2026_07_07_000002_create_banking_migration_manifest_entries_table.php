<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_migration_manifest_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('migration_plan_id')->index();
            $table->string('source_domain', 50);
            $table->string('source_model', 120);
            $table->ulid('source_ulid')->index();
            $table->ulid('source_property_id')->index();
            $table->string('source_identity_hash', 64);
            $table->string('source_snapshot_hash', 64);
            $table->string('dry_run_version', 64)->index();
            $table->string('inventory_status', 30)->index();
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['migration_plan_id', 'source_domain', 'source_model', 'source_ulid', 'dry_run_version'], 'manifest_unique_source_per_run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_migration_manifest_entries');
    }
};
