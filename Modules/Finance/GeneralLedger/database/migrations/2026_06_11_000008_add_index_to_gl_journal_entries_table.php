<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_journal_entries', function (Blueprint $table) {
            // SQLite has limited support for partial unique indexes in older versions via Schema Builder, 
            // but we can create a standard composite index and enforce partial logic at the service layer 
            // if partial indexing fails. In Laravel 11/13, unique() on standard DBs handles this well.
            // But since the DB might be SQLite for testing, we just use a standard index.
            $table->index(['property_id', 'source_module', 'source_type', 'source_id'], 'gl_je_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('gl_journal_entries', function (Blueprint $table) {
            $table->dropIndex('gl_je_source_idx');
        });
    }
};
