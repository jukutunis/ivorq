<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('bank_account_id')->index();
            
            $table->date('statement_date_start');
            $table->date('statement_date_end');
            
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('reconciled_balance', 15, 2)->default(0);
            $table->decimal('unreconciled_balance', 15, 2)->default(0);
            
            $table->string('status')->index(); // Open, InProgress, Review, Completed, Cancelled
            
            $table->timestamp('completed_at')->nullable();
            $table->ulid('completed_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->ulid('cancelled_by')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Partial unique index for Postgres
            $table->unique('bank_account_id', 'unique_active_session')
                ->whereRaw("status IN ('Open', 'InProgress', 'Review')");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_sessions');
    }
};
