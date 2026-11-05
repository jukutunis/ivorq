<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->string('posting_disposition', 16)->default('ACCEPTED')->index();
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->text('void_reason')->nullable();
            $table->ulid('voided_by')->nullable()->index();
            $table->timestamp('voided_at')->nullable();
        });

        Schema::create('goods_receipt_line_exceptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('goods_receipt_id')->index();
            $table->ulid('goods_receipt_line_id')->index();
            $table->ulid('purchase_order_id')->index();
            $table->ulid('purchase_order_line_id')->index();
            $table->string('discrepancy_type', 32)->nullable();
            $table->string('inspection_result', 16)->nullable();
            $table->decimal('observed_quantity', 12, 3);
            $table->decimal('accepted_quantity', 12, 3)->nullable();
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('OPEN');
            $table->string('resolution_type', 32)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->ulid('reported_by');
            $table->timestamp('reported_at');
            $table->ulid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('idempotency_key', 120);
            $table->char('source_fingerprint', 64);
            $table->timestamps();

            $table->unique(['property_id', 'idempotency_key'], 'uq_gr_line_exception_idempotency');
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->restrictOnDelete();
            $table->foreign('goods_receipt_line_id')->references('id')->on('goods_receipt_lines')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE goods_receipt_lines ADD CONSTRAINT ck_gr_line_posting_disposition CHECK (posting_disposition IN ('ACCEPTED','REJECTED'))");
        DB::statement("ALTER TABLE goods_receipt_line_exceptions ADD CONSTRAINT ck_gr_exception_status CHECK (status IN ('OPEN','RESOLVED'))");
        DB::statement("ALTER TABLE goods_receipt_line_exceptions ADD CONSTRAINT ck_gr_exception_resolution CHECK (resolution_type IS NULL OR resolution_type IN ('ACCEPT_AS_IS','ADJUST_ACCEPTED_QUANTITY','REJECT_LINE','VOID_RECEIPT'))");
        DB::statement('ALTER TABLE goods_receipt_line_exceptions ADD CONSTRAINT ck_gr_exception_observation CHECK (discrepancy_type IS NOT NULL OR inspection_result IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_guard_goods_receipt_exception_evidence()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Goods Receipt exception evidence cannot be deleted.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF OLD.status = 'RESOLVED' THEN
                    RAISE EXCEPTION 'Resolved Goods Receipt exception evidence is immutable.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF OLD.property_id IS DISTINCT FROM NEW.property_id
                    OR OLD.goods_receipt_id IS DISTINCT FROM NEW.goods_receipt_id
                    OR OLD.goods_receipt_line_id IS DISTINCT FROM NEW.goods_receipt_line_id
                    OR OLD.purchase_order_id IS DISTINCT FROM NEW.purchase_order_id
                    OR OLD.purchase_order_line_id IS DISTINCT FROM NEW.purchase_order_line_id
                    OR OLD.discrepancy_type IS DISTINCT FROM NEW.discrepancy_type
                    OR OLD.inspection_result IS DISTINCT FROM NEW.inspection_result
                    OR OLD.observed_quantity IS DISTINCT FROM NEW.observed_quantity
                    OR OLD.reason IS DISTINCT FROM NEW.reason
                    OR OLD.notes IS DISTINCT FROM NEW.notes
                    OR OLD.reported_by IS DISTINCT FROM NEW.reported_by
                    OR OLD.reported_at IS DISTINCT FROM NEW.reported_at
                    OR OLD.idempotency_key IS DISTINCT FROM NEW.idempotency_key
                    OR OLD.source_fingerprint IS DISTINCT FROM NEW.source_fingerprint THEN
                    RAISE EXCEPTION 'Goods Receipt exception source evidence is immutable.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.status <> 'RESOLVED' OR NEW.resolution_type IS NULL
                    OR NEW.resolved_by IS NULL OR NEW.resolved_at IS NULL THEN
                    RAISE EXCEPTION 'Goods Receipt exception resolution evidence is incomplete.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_guard_goods_receipt_exception_evidence
                BEFORE UPDATE OR DELETE ON goods_receipt_line_exceptions
                FOR EACH ROW EXECUTE FUNCTION fn_guard_goods_receipt_exception_evidence();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_guard_goods_receipt_exception_evidence ON goods_receipt_line_exceptions');
            DB::unprepared('DROP FUNCTION IF EXISTS fn_guard_goods_receipt_exception_evidence()');
        }

        Schema::dropIfExists('goods_receipt_line_exceptions');

        Schema::table('goods_receipt_lines', fn (Blueprint $table) => $table->dropColumn('posting_disposition'));
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['void_reason', 'voided_by', 'voided_at']);
        });
    }
};
