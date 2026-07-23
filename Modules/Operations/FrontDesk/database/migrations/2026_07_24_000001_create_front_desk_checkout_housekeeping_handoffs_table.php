<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_desk_checkout_housekeeping_handoffs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->char('reservation_id', 26);
            $table->char('checkout_execution_id', 26);
            $table->char('property_business_date_id', 26);
            $table->date('business_date');
            $table->string('idempotency_key', 255);
            $table->string('correlation_key', 255);
            $table->char('source_hash', 64);
            $table->string('delivery_status', 50)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->char('claim_token_hash', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            // Unique identities
            $table->unique(['property_id', 'idempotency_key'], 'fd_chh_idempotency_unique');
            $table->unique(['property_id', 'correlation_key'], 'fd_chh_correlation_unique');
            $table->unique(['property_id', 'source_hash'], 'fd_chh_source_hash_unique');
            $table->unique(['checkout_execution_id'], 'fd_chh_execution_unique');
            $table->unique(['front_desk_stay_id'], 'fd_chh_stay_unique');

            // Indexes
            $table->index('property_id', 'fd_chh_property_id_idx');
            $table->index('front_desk_stay_id', 'fd_chh_stay_id_idx');
            $table->index('reservation_id', 'fd_chh_reservation_id_idx');
            $table->index('property_business_date_id', 'fd_chh_business_date_id_idx');
            $table->index('delivery_status', 'fd_chh_delivery_status_idx');
            $table->index('available_at', 'fd_chh_available_at_idx');
            $table->index('claim_expires_at', 'fd_chh_claim_expires_at_idx');
            $table->index('occurred_at', 'fd_chh_occurred_at_idx');
            $table->index('created_at', 'fd_chh_created_at_idx');
            $table->index(['property_id', 'delivery_status', 'available_at'], 'fd_chh_claimable_idx');

            // Foreign keys — all RESTRICT
            $table->foreign('property_id', 'fd_chh_property_fk')
                ->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('front_desk_stay_id', 'fd_chh_stay_fk')
                ->references('id')->on('front_desk_stays')->restrictOnDelete();
            $table->foreign('reservation_id', 'fd_chh_reservation_fk')
                ->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('checkout_execution_id', 'fd_chh_execution_fk')
                ->references('id')->on('front_desk_checkout_executions')->restrictOnDelete();
            $table->foreign('property_business_date_id', 'fd_chh_business_date_fk')
                ->references('id')->on('property_business_dates')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Status check
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_status_check
                CHECK (delivery_status IN ('PENDING', 'CLAIMED', 'DELIVERED', 'FAILED'))
            ");

            // Idempotency key non-empty and trimmed
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_idempotency_check
                CHECK (btrim(idempotency_key) <> '' AND idempotency_key = btrim(idempotency_key))
            ");

            // Correlation key non-empty and trimmed
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_correlation_check
                CHECK (btrim(correlation_key) <> '' AND correlation_key = btrim(correlation_key))
            ");

            // Source hash is lowercase SHA-256 hex
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_source_hash_check
                CHECK (source_hash ~ '^[a-f0-9]{64}$')
            ");

            // Claim token hash is NULL or lowercase SHA-256 hex
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_claim_hash_check
                CHECK (claim_token_hash IS NULL OR claim_token_hash ~ '^[a-f0-9]{64}$')
            ");

            // Attempts >= 0
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_attempts_check
                CHECK (attempts >= 0)
            ");

            // Error code pattern
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_error_code_check
                CHECK (last_error_code IS NULL OR (
                    last_error_code = btrim(last_error_code)
                    AND btrim(last_error_code) <> ''
                    AND last_error_code ~ '^[A-Z0-9_]{1,100}$'
                ))
            ");

            // Claim timing
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_claim_timing_check
                CHECK (
                    (claimed_at IS NULL AND claim_expires_at IS NULL AND claim_token_hash IS NULL)
                    OR
                    (claimed_at IS NOT NULL AND claim_expires_at IS NOT NULL AND claim_token_hash IS NOT NULL AND claim_expires_at > claimed_at)
                )
            ");

            // State shape enforcement (strengthened)
            DB::statement("
                ALTER TABLE front_desk_checkout_housekeeping_handoffs
                ADD CONSTRAINT fd_chh_state_shape_check
                CHECK (
                    (delivery_status = 'PENDING'
                     AND attempts = 0
                     AND claimed_at IS NULL
                     AND claim_expires_at IS NULL
                     AND claim_token_hash IS NULL
                     AND delivered_at IS NULL
                     AND failed_at IS NULL
                     AND last_error_code IS NULL)
                    OR
                    (delivery_status = 'CLAIMED'
                     AND attempts >= 1
                     AND claimed_at IS NOT NULL
                     AND claim_expires_at IS NOT NULL
                     AND claim_token_hash IS NOT NULL
                     AND delivered_at IS NULL
                     AND failed_at IS NULL
                     AND last_error_code IS NULL)
                    OR
                    (delivery_status = 'DELIVERED'
                     AND attempts >= 1
                     AND claimed_at IS NOT NULL
                     AND claim_expires_at IS NOT NULL
                     AND claim_token_hash IS NOT NULL
                     AND delivered_at IS NOT NULL
                     AND failed_at IS NULL
                     AND last_error_code IS NULL)
                    OR
                    (delivery_status = 'FAILED'
                     AND attempts >= 1
                     AND claimed_at IS NOT NULL
                     AND claim_expires_at IS NOT NULL
                     AND claim_token_hash IS NOT NULL
                     AND delivered_at IS NULL
                     AND failed_at IS NOT NULL
                     AND last_error_code IS NOT NULL
                     AND available_at > failed_at)
                )
            ");

            // Source-relationship trigger function
            DB::statement("CREATE OR REPLACE FUNCTION fd_chh_check_source_relationship() RETURNS trigger AS \$\$
                DECLARE
                    exec_property_id CHAR(26);
                    exec_stay_id CHAR(26);
                    exec_reservation_id CHAR(26);
                    exec_business_date_id CHAR(26);
                    exec_business_date DATE;
                    exec_terminal_status VARCHAR(50);
                    exec_occurred_at TIMESTAMP;
                BEGIN
                    SELECT
                        property_id,
                        front_desk_stay_id,
                        reservation_id,
                        property_business_date_id,
                        business_date,
                        terminal_stay_status,
                        occurred_at
                    INTO
                        exec_property_id,
                        exec_stay_id,
                        exec_reservation_id,
                        exec_business_date_id,
                        exec_business_date,
                        exec_terminal_status,
                        exec_occurred_at
                    FROM front_desk_checkout_executions
                    WHERE id = NEW.checkout_execution_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF exec_property_id <> NEW.property_id THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF exec_stay_id <> NEW.front_desk_stay_id THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF exec_reservation_id <> NEW.reservation_id THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF exec_business_date_id <> NEW.property_business_date_id THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF exec_business_date <> NEW.business_date THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF exec_terminal_status <> 'CHECKED_OUT' THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    IF NEW.occurred_at < exec_occurred_at THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH';
                    END IF;

                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;");

            DB::statement('CREATE TRIGGER fd_chh_check_source BEFORE INSERT ON front_desk_checkout_housekeeping_handoffs FOR EACH ROW EXECUTE FUNCTION fd_chh_check_source_relationship()');

            // Mutation trigger function
            DB::statement("CREATE OR REPLACE FUNCTION fd_chh_enforce_mutation_rules() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN';
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        -- Reject immutable payload changes (explicit column checks)
                        IF NEW.property_id IS DISTINCT FROM OLD.property_id THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.front_desk_stay_id IS DISTINCT FROM OLD.front_desk_stay_id THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.reservation_id IS DISTINCT FROM OLD.reservation_id THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.checkout_execution_id IS DISTINCT FROM OLD.checkout_execution_id THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.property_business_date_id IS DISTINCT FROM OLD.property_business_date_id THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.business_date IS DISTINCT FROM OLD.business_date THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.correlation_key IS DISTINCT FROM OLD.correlation_key THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.source_hash IS DISTINCT FROM OLD.source_hash THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.occurred_at IS DISTINCT FROM OLD.occurred_at THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;
                        IF NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                            RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE';
                        END IF;

                        -- PENDING → CLAIMED
                        IF OLD.delivery_status = 'PENDING' AND NEW.delivery_status = 'CLAIMED' THEN
                            IF NEW.attempts <> OLD.attempts + 1 THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.available_at IS DISTINCT FROM OLD.available_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claimed_at IS NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_expires_at IS NULL OR NEW.claim_expires_at <= NEW.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_token_hash IS NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.delivered_at IS NOT NULL OR NEW.failed_at IS NOT NULL OR NEW.last_error_code IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            RETURN NEW;
                        END IF;

                        -- CLAIMED → CLAIMED (reclaim on expiry)
                        IF OLD.delivery_status = 'CLAIMED' AND NEW.delivery_status = 'CLAIMED' THEN
                            IF NEW.claimed_at < OLD.claim_expires_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.attempts <> OLD.attempts + 1 THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claimed_at IS NOT DISTINCT FROM OLD.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_expires_at IS NULL OR NEW.claim_expires_at <= NEW.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_token_hash IS NOT DISTINCT FROM OLD.claim_token_hash THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.available_at IS DISTINCT FROM OLD.available_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.delivered_at IS NOT NULL OR NEW.failed_at IS NOT NULL OR NEW.last_error_code IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            RETURN NEW;
                        END IF;

                        -- FAILED → CLAIMED (due retry)
                        IF OLD.delivery_status = 'FAILED' AND NEW.delivery_status = 'CLAIMED' THEN
                            IF NEW.claimed_at < OLD.available_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.attempts <> OLD.attempts + 1 THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claimed_at IS NOT DISTINCT FROM OLD.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_expires_at IS NULL OR NEW.claim_expires_at <= NEW.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_token_hash IS NOT DISTINCT FROM OLD.claim_token_hash THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.available_at IS DISTINCT FROM OLD.available_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.failed_at IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.last_error_code IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.delivered_at IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            RETURN NEW;
                        END IF;

                        -- CLAIMED → DELIVERED
                        IF OLD.delivery_status = 'CLAIMED' AND NEW.delivery_status = 'DELIVERED' THEN
                            IF NEW.attempts IS DISTINCT FROM OLD.attempts THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claimed_at IS DISTINCT FROM OLD.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_expires_at IS DISTINCT FROM OLD.claim_expires_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_token_hash IS DISTINCT FROM OLD.claim_token_hash THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.available_at IS DISTINCT FROM OLD.available_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.delivered_at IS NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.failed_at IS NOT NULL OR NEW.last_error_code IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            RETURN NEW;
                        END IF;

                        -- CLAIMED → FAILED
                        IF OLD.delivery_status = 'CLAIMED' AND NEW.delivery_status = 'FAILED' THEN
                            IF NEW.attempts IS DISTINCT FROM OLD.attempts THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claimed_at IS DISTINCT FROM OLD.claimed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_expires_at IS DISTINCT FROM OLD.claim_expires_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.claim_token_hash IS DISTINCT FROM OLD.claim_token_hash THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.failed_at IS NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.last_error_code IS NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.available_at <= NEW.failed_at THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            IF NEW.delivered_at IS NOT NULL THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            RETURN NEW;
                        END IF;

                        -- DELIVERED → DELIVERED (only true no-data replay)
                        IF OLD.delivery_status = 'DELIVERED' AND NEW.delivery_status = 'DELIVERED' THEN
                            IF NEW IS DISTINCT FROM OLD THEN
                                RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                            END IF;
                            RETURN NEW;
                        END IF;

                        -- Anything else is invalid
                        RAISE EXCEPTION 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION';
                    END IF;

                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;");

            DB::statement('CREATE TRIGGER fd_chh_enforce_mutation BEFORE UPDATE OR DELETE ON front_desk_checkout_housekeeping_handoffs FOR EACH ROW EXECUTE FUNCTION fd_chh_enforce_mutation_rules()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fd_chh_enforce_mutation ON front_desk_checkout_housekeeping_handoffs');
            DB::statement('DROP TRIGGER IF EXISTS fd_chh_check_source ON front_desk_checkout_housekeeping_handoffs');
            DB::statement('DROP FUNCTION IF EXISTS fd_chh_enforce_mutation_rules()');
            DB::statement('DROP FUNCTION IF EXISTS fd_chh_check_source_relationship()');
        }
        Schema::dropIfExists('front_desk_checkout_housekeeping_handoffs');
    }
};
