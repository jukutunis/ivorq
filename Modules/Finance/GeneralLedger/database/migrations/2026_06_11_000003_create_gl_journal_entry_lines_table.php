<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_journal_entry_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('journal_entry_id', 26);
            $table->char('account_id', 26);
            $table->char('department_id', 26)->nullable();
            
            $table->decimal('debit_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->string('memo')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_journal_entry_lines');
    }
};
