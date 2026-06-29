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
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->foreignUlid('receiving_document_id')
                ->nullable()
                ->after('external_reference');

            $table->index('receiving_document_id');
            $table->foreign('receiving_document_id')
                ->references('id')
                ->on('receiving_documents')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->dropForeign(['receiving_document_id']);
            $table->dropIndex(['receiving_document_id']);
            $table->dropColumn('receiving_document_id');
        });
    }
};
