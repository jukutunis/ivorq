<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('budget_budget_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('budget_id')->index();
            $table->integer('version_number');
            $table->string('status');
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['budget_id', 'version_number'], 'budget_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_budget_versions');
    }
};
