<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_migration_exception_quarantines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('migration_plan_id')->index();
            $table->ulid('manifest_entry_id')->nullable()->index();
            $table->string('exception_code', 60)->index();
            $table->string('severity', 20)->index();
            $table->string('source_domain', 50);
            $table->string('source_model', 120)->nullable();
            $table->ulid('source_ulid')->nullable()->index();
            $table->ulid('source_property_id')->nullable()->index();
            $table->string('correlation_id', 26)->index();
            $table->boolean('is_resolved')->default(false);
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['migration_plan_id', 'exception_code', 'source_domain', 'source_model', 'source_ulid'], 'quarantine_unique_exception_per_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_migration_exception_quarantines');
    }
};
