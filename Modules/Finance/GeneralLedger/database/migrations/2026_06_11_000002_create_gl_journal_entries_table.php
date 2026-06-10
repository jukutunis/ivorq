<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_journal_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->date('transaction_date');
            $table->date('posting_date')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('Draft'); // Draft, Posted, Voided
            
            $table->string('source_module')->nullable();
            $table->string('source_type')->nullable();
            $table->char('source_id', 26)->nullable();
            $table->char('reversal_of_id', 26)->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_journal_entries');
    }
};
