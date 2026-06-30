<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->ulid('draft_finalization_authorized_by')->nullable();
            $table->timestamp('draft_finalization_authorized_at')->nullable();

            $table->index('draft_finalization_authorized_by', 'idx_gl_je_draft_final_auth_by');
            $table->foreign('draft_finalization_authorized_by', 'fk_gl_je_draft_final_auth_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->dropForeign('fk_gl_je_draft_final_auth_by');
            $table->dropIndex('idx_gl_je_draft_final_auth_by');
            $table->dropColumn([
                'draft_finalization_authorized_by',
                'draft_finalization_authorized_at',
            ]);
        });
    }
};
