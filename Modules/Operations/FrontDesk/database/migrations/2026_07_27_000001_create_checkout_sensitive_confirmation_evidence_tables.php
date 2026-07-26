<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sensitive_confirmation_issuances', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('confirmation_identity', 26);
            $table->string('intent', 80);
            $table->char('actor_id', 26);
            $table->char('company_id', 26);
            $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->string('checkout_idempotency_key', 120);
            $table->char('session_fingerprint', 64);
            $table->char('confirmation_fingerprint', 64);
            $table->timestamp('confirmed_at');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->unique('confirmation_identity', 'p8_csc_issue_identity_unique');
            $table->unique('confirmation_fingerprint', 'p8_csc_issue_fingerprint_unique');
            $table->unique([
                'id', 'confirmation_identity', 'confirmation_fingerprint', 'actor_id', 'company_id',
                'property_id', 'front_desk_stay_id', 'checkout_idempotency_key',
            ], 'p8_csc_issue_context_unique');
            $table->index(['property_id', 'front_desk_stay_id'], 'p8_csc_issue_stay_idx');
            $table->index(['actor_id', 'property_id'], 'p8_csc_issue_actor_property_idx');
            $table->index('expires_at', 'p8_csc_issue_expires_idx');

            $table->foreign('actor_id', 'p8_csc_issue_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id', 'p8_csc_issue_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('property_id', 'p8_csc_issue_property_fk')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('front_desk_stay_id', 'p8_csc_issue_stay_fk')->references('id')->on('front_desk_stays')->restrictOnDelete();
        });

        Schema::create('checkout_sensitive_confirmation_consumptions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('issuance_id', 26);
            $table->char('confirmation_identity', 26);
            $table->char('confirmation_fingerprint', 64);
            $table->char('actor_id', 26);
            $table->char('company_id', 26);
            $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->string('checkout_idempotency_key', 120);
            $table->timestamp('consumed_at');
            $table->timestamp('created_at');

            $table->unique('issuance_id', 'p8_csc_consume_issuance_unique');
            $table->unique(['property_id', 'front_desk_stay_id', 'checkout_idempotency_key'], 'p8_csc_consume_checkout_unique');
            $table->index('confirmation_fingerprint', 'p8_csc_consume_fingerprint_idx');
            $table->index(['actor_id', 'property_id'], 'p8_csc_consume_actor_property_idx');

            $table->foreign('actor_id', 'p8_csc_consume_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id', 'p8_csc_consume_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('property_id', 'p8_csc_consume_property_fk')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('front_desk_stay_id', 'p8_csc_consume_stay_fk')->references('id')->on('front_desk_stays')->restrictOnDelete();
            $table->foreign([
                'issuance_id', 'confirmation_identity', 'confirmation_fingerprint', 'actor_id',
                'company_id', 'property_id', 'front_desk_stay_id', 'checkout_idempotency_key',
            ], 'p8_csc_consume_issue_context_fk')
                ->references([
                    'id', 'confirmation_identity', 'confirmation_fingerprint', 'actor_id',
                    'company_id', 'property_id', 'front_desk_stay_id', 'checkout_idempotency_key',
                ])->on('checkout_sensitive_confirmation_issuances')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_issuances
                ADD CONSTRAINT p8_csc_issue_intent_check CHECK (intent = 'frontdesk-checkout-execution')
            ");
            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_issuances
                ADD CONSTRAINT p8_csc_issue_idem_check
                CHECK (btrim(checkout_idempotency_key) <> '' AND checkout_idempotency_key = btrim(checkout_idempotency_key) AND checkout_idempotency_key !~ '[[:cntrl:]]')
            ");
            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_issuances
                ADD CONSTRAINT p8_csc_issue_session_sha CHECK (session_fingerprint ~ '^[a-f0-9]{64}$')
            ");
            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_issuances
                ADD CONSTRAINT p8_csc_issue_confirm_sha CHECK (confirmation_fingerprint ~ '^[a-f0-9]{64}$')
            ");
            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_issuances
                ADD CONSTRAINT p8_csc_issue_time_check CHECK (confirmed_at < expires_at AND created_at = confirmed_at)
            ");
            DB::statement("CREATE OR REPLACE FUNCTION p8_csc_issue_block_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'P8_CHECKOUT_CONFIRMATION_ISSUANCE_IMMUTABLE';
                END; $$ LANGUAGE plpgsql;");
            DB::statement('CREATE TRIGGER p8_csc_issue_no_update BEFORE UPDATE ON checkout_sensitive_confirmation_issuances FOR EACH ROW EXECUTE FUNCTION p8_csc_issue_block_mutation()');
            DB::statement('CREATE TRIGGER p8_csc_issue_no_delete BEFORE DELETE ON checkout_sensitive_confirmation_issuances FOR EACH ROW EXECUTE FUNCTION p8_csc_issue_block_mutation()');

            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_consumptions
                ADD CONSTRAINT p8_csc_consume_idem_check
                CHECK (btrim(checkout_idempotency_key) <> '' AND checkout_idempotency_key = btrim(checkout_idempotency_key) AND checkout_idempotency_key !~ '[[:cntrl:]]')
            ");
            DB::statement("
                ALTER TABLE checkout_sensitive_confirmation_consumptions
                ADD CONSTRAINT p8_csc_consume_confirm_sha CHECK (confirmation_fingerprint ~ '^[a-f0-9]{64}$')
            ");
            DB::statement("CREATE OR REPLACE FUNCTION p8_csc_consume_guard() RETURNS trigger AS $$
                DECLARE
                    issue_expires_at TIMESTAMP;
                    issue_confirmed_at TIMESTAMP;
                    wall_clock_utc TIMESTAMP;
                BEGIN
                    SELECT expires_at, confirmed_at
                    INTO issue_expires_at, issue_confirmed_at
                    FROM checkout_sensitive_confirmation_issuances
                    WHERE id = NEW.issuance_id
                    FOR UPDATE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_CONTEXT_MISMATCH';
                    END IF;

                    wall_clock_utc := clock_timestamp() AT TIME ZONE 'UTC';

                    IF issue_expires_at <= wall_clock_utc THEN
                        RAISE EXCEPTION 'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_EXPIRED';
                    END IF;

                    NEW.consumed_at := wall_clock_utc;
                    NEW.created_at := wall_clock_utc;

                    IF NEW.consumed_at < issue_confirmed_at THEN
                        RAISE EXCEPTION 'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_CONTEXT_MISMATCH';
                    END IF;

                    RETURN NEW;
                END; $$ LANGUAGE plpgsql;");
            DB::statement('CREATE TRIGGER p8_csc_consume_insert_guard BEFORE INSERT ON checkout_sensitive_confirmation_consumptions FOR EACH ROW EXECUTE FUNCTION p8_csc_consume_guard()');
            DB::statement("CREATE OR REPLACE FUNCTION p8_csc_consume_block_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE';
                END; $$ LANGUAGE plpgsql;");
            DB::statement('CREATE TRIGGER p8_csc_consume_no_update BEFORE UPDATE ON checkout_sensitive_confirmation_consumptions FOR EACH ROW EXECUTE FUNCTION p8_csc_consume_block_mutation()');
            DB::statement('CREATE TRIGGER p8_csc_consume_no_delete BEFORE DELETE ON checkout_sensitive_confirmation_consumptions FOR EACH ROW EXECUTE FUNCTION p8_csc_consume_block_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS p8_csc_consume_no_delete ON checkout_sensitive_confirmation_consumptions');
            DB::statement('DROP TRIGGER IF EXISTS p8_csc_consume_no_update ON checkout_sensitive_confirmation_consumptions');
            DB::statement('DROP TRIGGER IF EXISTS p8_csc_consume_insert_guard ON checkout_sensitive_confirmation_consumptions');
            DB::statement('DROP FUNCTION IF EXISTS p8_csc_consume_block_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS p8_csc_consume_guard()');
            DB::statement('DROP TRIGGER IF EXISTS p8_csc_issue_no_delete ON checkout_sensitive_confirmation_issuances');
            DB::statement('DROP TRIGGER IF EXISTS p8_csc_issue_no_update ON checkout_sensitive_confirmation_issuances');
            DB::statement('DROP FUNCTION IF EXISTS p8_csc_issue_block_mutation()');
        }

        Schema::dropIfExists('checkout_sensitive_confirmation_consumptions');
        Schema::dropIfExists('checkout_sensitive_confirmation_issuances');
    }
};
