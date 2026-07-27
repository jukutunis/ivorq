<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('front_desk_checkout_executions', function (Blueprint $table) {
            $table->char('checkout_confirmation_consumption_id', 26)->nullable()->after('id');
            $table->char('checkout_confirmation_fingerprint', 64)->nullable()->after('checkout_confirmation_consumption_id');
            $table->timestamp('checkout_confirmed_at')->nullable()->after('checkout_confirmation_fingerprint');
            $table->timestamp('checkout_confirmation_expires_at')->nullable()->after('checkout_confirmed_at');
            $table->timestamp('checkout_confirmation_consumed_at')->nullable()->after('checkout_confirmation_expires_at');

            $table->unique('checkout_confirmation_consumption_id', 'fd_ce_p8_confirmation_consumption_unique');
            $table->index('checkout_confirmation_fingerprint', 'fd_ce_p8_confirmation_fingerprint_idx');
            $table->foreign('checkout_confirmation_consumption_id', 'fd_ce_p8_confirmation_consumption_fk')
                ->references('id')->on('checkout_sensitive_confirmation_consumptions')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_p8_confirmation_all_or_none
                CHECK (
                    (checkout_confirmation_consumption_id IS NULL
                     AND checkout_confirmation_fingerprint IS NULL
                     AND checkout_confirmed_at IS NULL
                     AND checkout_confirmation_expires_at IS NULL
                     AND checkout_confirmation_consumed_at IS NULL)
                    OR
                    (checkout_confirmation_consumption_id IS NOT NULL
                     AND checkout_confirmation_fingerprint IS NOT NULL
                     AND checkout_confirmed_at IS NOT NULL
                     AND checkout_confirmation_expires_at IS NOT NULL
                     AND checkout_confirmation_consumed_at IS NOT NULL)
                )
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_p8_confirmation_fingerprint_sha
                CHECK (checkout_confirmation_fingerprint IS NULL OR checkout_confirmation_fingerprint ~ '^[a-f0-9]{64}$')
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_p8_confirmation_time_order
                CHECK (
                    checkout_confirmed_at IS NULL
                    OR (
                        checkout_confirmed_at < checkout_confirmation_expires_at
                        AND checkout_confirmed_at <= checkout_confirmation_consumed_at
                        AND checkout_confirmation_consumed_at < checkout_confirmation_expires_at
                    )
                )
            ");

            DB::statement("CREATE OR REPLACE FUNCTION fd_ce_p8_confirmation_source_guard() RETURNS trigger AS $$
                DECLARE
                    consume_issuance_id CHAR(26);
                    consume_fingerprint CHAR(64);
                    consume_actor_id CHAR(26);
                    consume_property_id CHAR(26);
                    consume_stay_id CHAR(26);
                    consume_idempotency_key VARCHAR(120);
                    consume_consumed_at TIMESTAMP;
                    issue_confirmed_at TIMESTAMP;
                    issue_expires_at TIMESTAMP;
                BEGIN
                    IF NEW.checkout_confirmation_consumption_id IS NULL THEN
                        RETURN NEW;
                    END IF;

                    SELECT c.issuance_id, c.confirmation_fingerprint, c.actor_id, c.property_id,
                           c.front_desk_stay_id, c.checkout_idempotency_key, c.consumed_at,
                           i.confirmed_at, i.expires_at
                    INTO consume_issuance_id, consume_fingerprint, consume_actor_id, consume_property_id,
                         consume_stay_id, consume_idempotency_key, consume_consumed_at,
                         issue_confirmed_at, issue_expires_at
                    FROM checkout_sensitive_confirmation_consumptions c
                    JOIN checkout_sensitive_confirmation_issuances i ON i.id = c.issuance_id
                    WHERE c.id = NEW.checkout_confirmation_consumption_id
                    FOR SHARE;

                    IF NOT FOUND
                        OR consume_issuance_id IS NULL
                        OR NEW.property_id IS DISTINCT FROM consume_property_id
                        OR NEW.front_desk_stay_id IS DISTINCT FROM consume_stay_id
                        OR NEW.idempotency_key IS DISTINCT FROM consume_idempotency_key
                        OR NEW.created_by IS DISTINCT FROM consume_actor_id
                        OR NEW.checkout_confirmation_fingerprint IS DISTINCT FROM consume_fingerprint
                        OR NEW.checkout_confirmation_consumed_at IS DISTINCT FROM consume_consumed_at
                        OR NEW.checkout_confirmed_at IS DISTINCT FROM issue_confirmed_at
                        OR NEW.checkout_confirmation_expires_at IS DISTINCT FROM issue_expires_at THEN
                        RAISE EXCEPTION 'P8_CHECKOUT_EXECUTION_CONFIRMATION_SOURCE_MISMATCH';
                    END IF;

                    RETURN NEW;
                END; $$ LANGUAGE plpgsql;");
            DB::statement('CREATE TRIGGER fd_ce_p8_confirmation_source_guard BEFORE INSERT ON front_desk_checkout_executions FOR EACH ROW EXECUTE FUNCTION fd_ce_p8_confirmation_source_guard()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE front_desk_checkout_executions DROP CONSTRAINT IF EXISTS fd_ce_p8_confirmation_time_order');
            DB::statement('ALTER TABLE front_desk_checkout_executions DROP CONSTRAINT IF EXISTS fd_ce_p8_confirmation_fingerprint_sha');
            DB::statement('ALTER TABLE front_desk_checkout_executions DROP CONSTRAINT IF EXISTS fd_ce_p8_confirmation_all_or_none');
            DB::statement('DROP TRIGGER IF EXISTS fd_ce_p8_confirmation_source_guard ON front_desk_checkout_executions');
            DB::statement('DROP FUNCTION IF EXISTS fd_ce_p8_confirmation_source_guard()');
        }

        Schema::table('front_desk_checkout_executions', function (Blueprint $table) {
            $table->dropForeign('fd_ce_p8_confirmation_consumption_fk');
            $table->dropUnique('fd_ce_p8_confirmation_consumption_unique');
            $table->dropIndex('fd_ce_p8_confirmation_fingerprint_idx');
            $table->dropColumn([
                'checkout_confirmation_consumption_id',
                'checkout_confirmation_fingerprint',
                'checkout_confirmed_at',
                'checkout_confirmation_expires_at',
                'checkout_confirmation_consumed_at',
            ]);
        });
    }
};
