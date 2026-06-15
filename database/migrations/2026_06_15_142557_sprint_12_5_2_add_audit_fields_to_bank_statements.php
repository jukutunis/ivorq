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
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->string('file_name')->nullable();
            $table->string('file_hash')->nullable();
            $table->integer('row_count')->default(0);
            $table->char('imported_by', 26)->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropColumn([
                'file_name',
                'file_hash',
                'row_count',
                'imported_by',
                'imported_at'
            ]);
        });
    }
};
