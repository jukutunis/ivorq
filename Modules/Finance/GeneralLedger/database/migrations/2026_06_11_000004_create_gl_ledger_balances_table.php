<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_ledger_balances', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('account_id', 26);
            $table->integer('period_year');
            $table->integer('period_month');
            
            $table->decimal('debit_total', 15, 2)->default(0);
            $table->decimal('credit_total', 15, 2)->default(0);
            $table->decimal('ending_balance', 15, 2)->default(0);

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'account_id', 'period_year', 'period_month'], 'gl_ledger_balances_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_ledger_balances');
    }
};
