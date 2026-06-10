<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('bank_account_id', 26);
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->decimal('imported_closing_balance', 15, 2)->nullable();
            $table->string('status')->default('Draft');
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // BR-003: No overlapping statement date per account.
            $table->unique(['bank_account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
