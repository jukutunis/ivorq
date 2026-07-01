<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_executions', function (Blueprint $table) {
            $table->ulid('controlled_bank_account_id')->nullable()->index()->after('operational_gl_account_id');
            $table->ulid('controlled_bank_statement_line_id')->nullable()->unique()->after('controlled_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_executions', function (Blueprint $table) {
            $table->dropColumn([
                'controlled_bank_account_id',
                'controlled_bank_statement_line_id',
            ]);
        });
    }
};
