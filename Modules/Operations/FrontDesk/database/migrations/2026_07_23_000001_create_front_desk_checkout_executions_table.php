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
            $table->string('id', 26)->primary();
            $table->string('property_id', 26);
            $table->string('front_desk_stay_id', 26);
            $table->string('reservation_id', 26);
            $table->string('idempotency_key');
            $table->string('terminal_stay_status', 50);
            $table->string('front_desk_final_review_id', 26);
            $table->string('property_business_date_id', 26);
            $table->date('business_date');
            $table->string('night_audit_source_status');
            $table->char('night_audit_source_fingerprint', 64);
            $table->string('pms_financial_attestation_status');
            $table->char('pms_financial_attestation_fingerprint', 64);
            $table->string('general_cashier_attestation_status');
            $table->char('general_cashier_attestation_fingerprint', 64);
            $table->char('source_hash', 64);
            $table->timestamp('occurred_at');
            $table->string('created_by', 26);
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
                CHECK (idempotency_key <> '' AND idempotency_key !~ '^\\s+$')
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
