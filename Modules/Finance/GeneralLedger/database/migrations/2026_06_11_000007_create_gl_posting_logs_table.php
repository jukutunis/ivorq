<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_posting_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->string('source_module');
            $table->string('source_type');
            $table->char('source_id', 26);
            $table->string('status'); // Success, Failed
            $table->text('error_message')->nullable();
            $table->char('journal_entry_id', 26)->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_posting_logs');
    }
};
