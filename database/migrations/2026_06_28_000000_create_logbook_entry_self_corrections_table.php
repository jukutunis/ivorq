<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook_entry_self_corrections', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('logbook_entry_id', 26);
            $table->text('correction_reason');
            $table->text('correction_content');
            $table->char('corrected_by', 26);
            $table->dateTime('corrected_at');
            $table->dateTime('created_at'); // no updated_at required

            $table->foreign('logbook_entry_id')
                  ->references('id')
                  ->on('logbook_entries')
                  ->restrictOnDelete();

            $table->index(['property_id', 'logbook_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_entry_self_corrections');
    }
};
