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
        Schema::create('budget_budget_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('budget_version_id')->index();
            $table->ulid('action_by_id')->index();
            $table->string('action');
            $table->text('comments')->nullable();
            $table->timestamp('action_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_budget_approvals');
    }
};
