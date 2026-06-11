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
        Schema::create('treasury_bank_balance_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('bank_account_id')->index();
            $table->date('snapshot_date');
            $table->decimal('balance', 19, 4)->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'bank_account_id', 'snapshot_date'], 'treasury_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_bank_balance_snapshots');
    }
};
