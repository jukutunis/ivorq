<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('bank_statement_id', 26);
            $table->date('transaction_date');
            $table->string('description');
            $table->string('reference')->nullable();
            $table->string('external_reference')->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_reconciled')->default(false);
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // BR-004: Imported lines cannot be duplicated. 
            // We enforce this by hashing or composite unique index. 
            // We use a composite unique index on statement ID, date, desc, ref, amount.
            $table->unique([
                'bank_statement_id', 
                'transaction_date', 
                'description', 
                'reference', 
                'amount'
            ], 'unique_statement_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
