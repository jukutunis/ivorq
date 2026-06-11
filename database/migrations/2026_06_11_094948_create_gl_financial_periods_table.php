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
        Schema::create('gl_financial_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->integer('period_year');
            $table->integer('period_month');
            $table->string('status');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closing_snapshot_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('opened_by')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'period_year', 'period_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_financial_periods');
    }
};
