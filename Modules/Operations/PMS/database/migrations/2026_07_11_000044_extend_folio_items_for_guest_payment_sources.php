<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyPaymentItems = DB::table('folio_items')
            ->whereIn('item_type', ['payment', 'deposit'])
            ->count();

        if ($legacyPaymentItems > 0) {
            throw new RuntimeException(
                "GLF_B_BLOCKED_LEGACY_PAYMENT_ITEMS: Found {$legacyPaymentItems} source-ambiguous Payment/Deposit FolioItem rows."
            );
        }

        Schema::table('folio_items', function (Blueprint $table) {
            $table->string('source_domain', 64)->nullable()->after('created_by');
            $table->string('source_type', 80)->nullable()->after('source_domain');
            $table->char('source_id', 26)->nullable()->after('source_type');
            $table->char('reverses_folio_item_id', 26)->nullable()->after('source_id');

            $table->unique(['property_id', 'id'], 'folio_items_property_id_unique');
            $table->foreign(['property_id', 'reverses_folio_item_id'], 'folio_items_property_reverses_item_foreign')
                ->references(['property_id', 'id'])->on('folio_items')->restrictOnDelete();
            $table->unique(['property_id', 'source_domain', 'source_type', 'source_id'], 'folio_items_property_source_unique');
            $table->index(['property_id', 'source_domain', 'source_type'], 'folio_items_property_source_index');
        });

        DB::statement("ALTER TABLE folio_items ADD CONSTRAINT folio_items_source_all_or_none_check CHECK ((source_domain IS NULL AND source_type IS NULL AND source_id IS NULL) OR (source_domain IS NOT NULL AND source_type IS NOT NULL AND source_id IS NOT NULL))");
        DB::statement("ALTER TABLE folio_items ADD CONSTRAINT folio_items_guest_payment_source_check CHECK (source_domain IS NULL OR (source_domain = 'pms_cashiering' AND source_type IN ('guest_payment_allocation','guest_payment_allocation_reversal')))");
        DB::statement("ALTER TABLE folio_items ADD CONSTRAINT folio_items_payment_reversal_link_check CHECK ((item_type = 'payment_reversal' AND reverses_folio_item_id IS NOT NULL) OR (item_type <> 'payment_reversal' AND reverses_folio_item_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_payment_reversal_link_check');
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_guest_payment_source_check');
        DB::statement('ALTER TABLE folio_items DROP CONSTRAINT IF EXISTS folio_items_source_all_or_none_check');

        Schema::table('folio_items', function (Blueprint $table) {
            $table->dropUnique('folio_items_property_source_unique');
            $table->dropIndex('folio_items_property_source_index');
            $table->dropForeign('folio_items_property_reverses_item_foreign');
            $table->dropUnique('folio_items_property_id_unique');
            $table->dropColumn([
                'source_domain',
                'source_type',
                'source_id',
                'reverses_folio_item_id',
            ]);
        });
    }
};
