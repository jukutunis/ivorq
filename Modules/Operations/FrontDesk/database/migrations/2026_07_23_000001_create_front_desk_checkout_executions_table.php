<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_desk_checkout_executions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->char('reservation_id', 26);
            $table->string('idempotency_key');
            $table->string('terminal_stay_status', 50);
            $table->char('front_desk_final_review_id', 26);
            $table->char('property_business_date_id', 26);
            $table->date('business_date');
            $table->string('night_audit_source_status');
            $table->char('night_audit_source_fingerprint', 64);
            $table->string('pms_financial_attestation_status');
            $table->char('pms_financial_attestation_fingerprint', 64);
            $table->string('general_cashier_attestation_status');
            $table->char('general_cashier_attestation_fingerprint', 64);
            $table->char('source_hash', 64);
            $table->timestamp('occurred_at');
            $table->char('created_by', 26);
            $table->timestamp('created_at');

            $table->unique(['property_id', 'idempotency_key'], 'fd_ce_idempotency_unique');
            $table->unique(['front_desk_stay_id'], 'fd_ce_stay_unique');
            $table->unique(['property_id', 'source_hash'], 'fd_ce_source_hash_unique');

            $table->index('property_id', 'fd_ce_property_id_idx');
            $table->index('front_desk_stay_id', 'fd_ce_stay_id_idx');
            $table->index('reservation_id', 'fd_ce_reservation_id_idx');
            $table->index('front_desk_final_review_id', 'fd_ce_final_review_id_idx');
            $table->index('property_business_date_id', 'fd_ce_business_date_id_idx');
            $table->index('occurred_at', 'fd_ce_occurred_at_idx');
            $table->index('created_at', 'fd_ce_created_at_idx');

            // Foreign keys — all RESTRICT, no CASCADE, no SET NULL
            $table->foreign('property_id', 'fd_ce_property_fk')
                ->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('front_desk_stay_id', 'fd_ce_stay_fk')
                ->references('id')->on('front_desk_stays')->restrictOnDelete();
            $table->foreign('reservation_id', 'fd_ce_reservation_fk')
                ->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('front_desk_final_review_id', 'fd_ce_final_review_fk')
                ->references('id')->on('front_desk_departure_checkout_final_reviews')->restrictOnDelete();
            $table->foreign('property_business_date_id', 'fd_ce_business_date_fk')
                ->references('id')->on('property_business_dates')->restrictOnDelete();
            $table->foreign('created_by', 'fd_ce_created_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_terminal_status_check
                CHECK (terminal_stay_status = 'CHECKED_OUT')
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_idempotency_not_blank
                CHECK (btrim(idempotency_key) <> '' AND idempotency_key = btrim(idempotency_key))
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_na_fingerprint_sha256
                CHECK (night_audit_source_fingerprint ~ '^[a-f0-9]{64}$')
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_pms_fingerprint_sha256
                CHECK (pms_financial_attestation_fingerprint ~ '^[a-f0-9]{64}$')
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_gc_fingerprint_sha256
                CHECK (general_cashier_attestation_fingerprint ~ '^[a-f0-9]{64}$')
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_source_hash_sha256
                CHECK (source_hash ~ '^[a-f0-9]{64}$')
            ");

            DB::statement("CREATE OR REPLACE FUNCTION fd_ce_block_mutation() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE';
                    ELSIF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE';
                    END IF; RETURN NULL;
                END; $$ LANGUAGE plpgsql;");

            DB::statement('CREATE TRIGGER fd_ce_block_update BEFORE UPDATE ON front_desk_checkout_executions FOR EACH ROW EXECUTE FUNCTION fd_ce_block_mutation()');
            DB::statement('CREATE TRIGGER fd_ce_block_delete BEFORE DELETE ON front_desk_checkout_executions FOR EACH ROW EXECUTE FUNCTION fd_ce_block_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fd_ce_block_update ON front_desk_checkout_executions');
            DB::statement('DROP TRIGGER IF EXISTS fd_ce_block_delete ON front_desk_checkout_executions');
            DB::statement('DROP FUNCTION IF EXISTS fd_ce_block_mutation()');
        }
        Schema::dropIfExists('front_desk_checkout_executions');
    }
};
