<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('master_account_id', 26)->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('normal_balance')->default('Debit'); // Debit, Credit, None
            $table->string('account_type')->default('Expense'); // Asset, Liability, Equity, Revenue, CostOfSales, Expense, Statistical
            $table->boolean('is_active')->default(true);
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};
