<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proposal_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('payment_proposal_id')->index();
            $table->ulid('property_id')->index();
            $table->ulid('source_journal_entry_id')->index();
            $table->ulid('source_journal_candidate_id')->index();
            $table->ulid('supplier_invoice_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('currency_code', 3);
            $table->decimal('source_amount', 19, 2);
            $table->boolean('is_active')->default(true)->index();
            $table->json('source_snapshot');
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('payment_proposal_id')
                ->references('id')
                ->on('payment_proposals')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX payment_proposal_items_active_source
                ON payment_proposal_items (property_id, source_journal_entry_id)
                WHERE is_active = true
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payment_proposal_items_active_source');
        }

        Schema::dropIfExists('payment_proposal_items');
    }
};
