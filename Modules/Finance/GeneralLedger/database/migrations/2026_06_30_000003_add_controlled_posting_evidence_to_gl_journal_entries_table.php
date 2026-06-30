<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->ulid('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->index('posted_by', 'idx_gl_je_posted_by');
            $table->foreign('posted_by', 'fk_gl_je_posted_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->dropForeign('fk_gl_je_posted_by');
            $table->dropIndex('idx_gl_je_posted_by');
            $table->dropColumn([
                'posted_by',
                'posted_at',
            ]);
        });
    }
};
