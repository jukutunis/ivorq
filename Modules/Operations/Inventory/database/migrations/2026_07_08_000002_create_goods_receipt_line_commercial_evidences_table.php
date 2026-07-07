<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_line_commercial_evidences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('goods_receipt_id', 26);
            $table->char('goods_receipt_line_id', 26);
            $table->char('purchase_order_id', 26);
            $table->char('purchase_order_line_id', 26);
            $table->char('inventory_item_id', 26);
            $table->char('inventory_unit_id', 26);
            $table->string('property_base_currency_code_snapshot', 3);
            $table->string('purchase_order_currency_code_snapshot', 10);
            $table->decimal('purchase_order_unit_cost_snapshot', 14, 2);
            $table->decimal('purchase_order_exchange_rate_snapshot', 14, 4)->nullable();
            $table->string('commercial_evidence_hash', 64);
            $table->timestamp('captured_at');
            $table->char('created_by', 26);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('goods_receipt_line_id', 'uq_commercial_evidence_gr_line');
            $table->index('property_id');
            $table->index('goods_receipt_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            CREATE OR REPLACE FUNCTION fn_block_commercial_evidence_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Receipt commercial evidence is immutable and cannot be updated.'
                        USING ERRCODE = 'integrity_constraint_violation';
                ELSIF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Receipt commercial evidence is immutable and cannot be deleted.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_block_commercial_evidence_mutation ON goods_receipt_line_commercial_evidences;
            CREATE TRIGGER trg_block_commercial_evidence_mutation
                BEFORE UPDATE OR DELETE ON goods_receipt_line_commercial_evidences
                FOR EACH ROW
                EXECUTE FUNCTION fn_block_commercial_evidence_mutation();
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_block_commercial_evidence_mutation ON goods_receipt_line_commercial_evidences');
            DB::unprepared('DROP FUNCTION IF EXISTS fn_block_commercial_evidence_mutation()');
        }

        Schema::dropIfExists('goods_receipt_line_commercial_evidences');
    }
};
