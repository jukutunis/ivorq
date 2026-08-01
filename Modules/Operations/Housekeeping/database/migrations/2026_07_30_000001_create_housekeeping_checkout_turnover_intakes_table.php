<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_checkout_turnover_intakes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('front_desk_checkout_housekeeping_handoff_id', 26);
            $table->char('checkout_execution_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->char('reservation_id', 26);
            $table->char('room_id', 26);
            $table->char('property_business_date_id', 26);
            $table->date('business_date');
            $table->char('cleaning_task_id', 26);
            $table->char('room_readiness_transition_id', 26);
            $table->char('handoff_source_hash', 64);
            $table->char('checkout_execution_source_hash', 64);
            $table->char('source_hash', 64);
            $table->string('room_readiness_before', 30);
            $table->string('room_readiness_after', 30);
            $table->string('cleanliness_before', 30);
            $table->string('cleanliness_after', 30);
            $table->string('consumer_identity', 120);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->foreign('property_id', 'hk_cti_property_fk')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('front_desk_checkout_housekeeping_handoff_id', 'hk_cti_handoff_fk')->references('id')->on('front_desk_checkout_housekeeping_handoffs')->restrictOnDelete();
            $table->foreign('checkout_execution_id', 'hk_cti_execution_fk')->references('id')->on('front_desk_checkout_executions')->restrictOnDelete();
            $table->foreign('front_desk_stay_id', 'hk_cti_stay_fk')->references('id')->on('front_desk_stays')->restrictOnDelete();
            $table->foreign('reservation_id', 'hk_cti_reservation_fk')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('room_id', 'hk_cti_room_fk')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('property_business_date_id', 'hk_cti_business_date_fk')->references('id')->on('property_business_dates')->restrictOnDelete();
            $table->foreign('cleaning_task_id', 'hk_cti_cleaning_task_fk')->references('id')->on('cleaning_tasks')->restrictOnDelete();
            $table->foreign('room_readiness_transition_id', 'hk_cti_transition_fk')->references('id')->on('housekeeping_room_readiness_transitions')->restrictOnDelete();

            $table->unique(['property_id', 'front_desk_checkout_housekeeping_handoff_id'], 'hk_cti_handoff_unique');
            $table->unique(['property_id', 'checkout_execution_id'], 'hk_cti_execution_unique');
            $table->unique('cleaning_task_id', 'hk_cti_cleaning_task_unique');
            $table->unique('room_readiness_transition_id', 'hk_cti_transition_unique');
            $table->unique(['property_id', 'source_hash'], 'hk_cti_source_hash_unique');

            $table->index(['property_id', 'room_id'], 'hk_cti_room_idx');
            $table->index(['property_id', 'business_date'], 'hk_cti_business_date_idx');
            $table->index('created_at', 'hk_cti_created_at_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE housekeeping_checkout_turnover_intakes
            ADD CONSTRAINT hk_cti_handoff_hash_check
            CHECK (handoff_source_hash ~ '^[a-f0-9]{64}$')
        ");
        DB::statement("
            ALTER TABLE housekeeping_checkout_turnover_intakes
            ADD CONSTRAINT hk_cti_execution_hash_check
            CHECK (checkout_execution_source_hash ~ '^[a-f0-9]{64}$')
        ");
        DB::statement("
            ALTER TABLE housekeeping_checkout_turnover_intakes
            ADD CONSTRAINT hk_cti_source_hash_check
            CHECK (source_hash ~ '^[a-f0-9]{64}$')
        ");
        DB::statement("
            ALTER TABLE housekeeping_checkout_turnover_intakes
            ADD CONSTRAINT hk_cti_consumer_identity_check
            CHECK (btrim(consumer_identity) <> '' AND consumer_identity = btrim(consumer_identity))
        ");
        DB::statement("
            ALTER TABLE housekeeping_checkout_turnover_intakes
            ADD CONSTRAINT hk_cti_state_shape_check
            CHECK (
                room_readiness_before IN ('dirty', 'waiting_cleaning', 'ready_for_sale', 'ready_for_arrival', 'ready_for_vip')
                AND room_readiness_after = 'waiting_cleaning'
                AND cleanliness_before IN ('dirty', 'clean', 'inspected')
                AND cleanliness_after = 'dirty'
            )
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION hk_cti_no_update()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'HK_P11_CHECKOUT_TURNOVER_INTAKE_IMMUTABLE';
            END;
            $$ LANGUAGE plpgsql
        ");
        DB::statement("
            CREATE OR REPLACE FUNCTION hk_cti_no_delete()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'HK_P11_CHECKOUT_TURNOVER_INTAKE_DELETE_FORBIDDEN';
            END;
            $$ LANGUAGE plpgsql
        ");
        DB::statement("
            CREATE OR REPLACE FUNCTION hk_cti_check_source_relationship()
            RETURNS trigger AS $$
            DECLARE
                h_property_id CHAR(26);
                h_stay_id CHAR(26);
                h_reservation_id CHAR(26);
                h_execution_id CHAR(26);
                h_business_date_id CHAR(26);
                h_business_date DATE;
                h_source_hash CHAR(64);
                h_delivery_status VARCHAR(50);
                e_property_id CHAR(26);
                e_stay_id CHAR(26);
                e_reservation_id CHAR(26);
                e_business_date_id CHAR(26);
                e_business_date DATE;
                e_terminal_status VARCHAR(40);
                e_source_hash CHAR(64);
                s_property_id CHAR(26);
                s_reservation_id CHAR(26);
                s_status VARCHAR(40);
                s_room_id CHAR(26);
                r_property_id CHAR(26);
                r_active BOOLEAN;
                t_property_id CHAR(26);
                t_room_id CHAR(26);
                t_type VARCHAR(30);
                t_source_type VARCHAR(100);
                t_source_id CHAR(26);
                t_to_status VARCHAR(30);
                c_property_id CHAR(26);
                c_room_id CHAR(26);
                c_task_type VARCHAR(50);
            BEGIN
                SELECT property_id, front_desk_stay_id, reservation_id, checkout_execution_id,
                       property_business_date_id, business_date, source_hash, delivery_status
                INTO h_property_id, h_stay_id, h_reservation_id, h_execution_id,
                     h_business_date_id, h_business_date, h_source_hash, h_delivery_status
                FROM front_desk_checkout_housekeeping_handoffs
                WHERE id = NEW.front_desk_checkout_housekeeping_handoff_id;

                IF NOT FOUND
                   OR h_property_id <> NEW.property_id
                   OR h_execution_id <> NEW.checkout_execution_id
                   OR h_stay_id <> NEW.front_desk_stay_id
                   OR h_reservation_id <> NEW.reservation_id
                   OR h_business_date_id <> NEW.property_business_date_id
                   OR h_business_date <> NEW.business_date
                   OR h_source_hash <> NEW.handoff_source_hash
                   OR h_delivery_status NOT IN ('CLAIMED', 'DELIVERED') THEN
                    RAISE EXCEPTION 'HK_P11_SOURCE_CONFLICT';
                END IF;

                SELECT property_id, front_desk_stay_id, reservation_id, property_business_date_id,
                       business_date, terminal_stay_status, source_hash
                INTO e_property_id, e_stay_id, e_reservation_id, e_business_date_id,
                     e_business_date, e_terminal_status, e_source_hash
                FROM front_desk_checkout_executions
                WHERE id = NEW.checkout_execution_id;

                IF NOT FOUND
                   OR e_property_id <> NEW.property_id
                   OR e_stay_id <> NEW.front_desk_stay_id
                   OR e_reservation_id <> NEW.reservation_id
                   OR e_business_date_id <> NEW.property_business_date_id
                   OR e_business_date <> NEW.business_date
                   OR e_source_hash <> NEW.checkout_execution_source_hash
                   OR e_terminal_status <> 'CHECKED_OUT' THEN
                    RAISE EXCEPTION 'HK_P11_SOURCE_CONFLICT';
                END IF;

                SELECT property_id, reservation_id, status, current_room_id
                INTO s_property_id, s_reservation_id, s_status, s_room_id
                FROM front_desk_stays
                WHERE id = NEW.front_desk_stay_id;

                IF NOT FOUND
                   OR s_property_id <> NEW.property_id
                   OR s_reservation_id <> NEW.reservation_id
                   OR s_status <> 'CHECKED_OUT'
                   OR s_room_id IS DISTINCT FROM NEW.room_id THEN
                    RAISE EXCEPTION 'HK_P11_SOURCE_CONFLICT';
                END IF;

                SELECT property_id, is_active
                INTO r_property_id, r_active
                FROM rooms
                WHERE id = NEW.room_id;

                IF NOT FOUND OR r_property_id <> NEW.property_id OR r_active IS DISTINCT FROM TRUE THEN
                    RAISE EXCEPTION 'HK_P11_ROOM_UNAVAILABLE';
                END IF;

                SELECT property_id, room_id, task_type
                INTO c_property_id, c_room_id, c_task_type
                FROM cleaning_tasks
                WHERE id = NEW.cleaning_task_id;

                IF NOT FOUND OR c_property_id <> NEW.property_id OR c_room_id IS DISTINCT FROM NEW.room_id OR c_task_type IS DISTINCT FROM 'checkout_cleaning' THEN
                    RAISE EXCEPTION 'HK_P11_SOURCE_CONFLICT';
                END IF;

                SELECT property_id, room_id, transition_type, source_type, source_id, to_status
                INTO t_property_id, t_room_id, t_type, t_source_type, t_source_id, t_to_status
                FROM housekeeping_room_readiness_transitions
                WHERE id = NEW.room_readiness_transition_id;

                IF NOT FOUND
                   OR t_property_id <> NEW.property_id
                   OR t_room_id <> NEW.room_id
                   OR t_type <> 'CHECKOUT_TURNOVER_INTAKE'
                   OR t_source_type IS DISTINCT FROM 'front_desk_checkout_housekeeping_handoff'
                   OR t_source_id IS DISTINCT FROM NEW.front_desk_checkout_housekeeping_handoff_id
                   OR t_to_status <> 'waiting_cleaning' THEN
                    RAISE EXCEPTION 'HK_P11_SOURCE_CONFLICT';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");
        DB::statement('CREATE TRIGGER hk_cti_no_update_trigger BEFORE UPDATE ON housekeeping_checkout_turnover_intakes FOR EACH ROW EXECUTE FUNCTION hk_cti_no_update()');
        DB::statement('CREATE TRIGGER hk_cti_no_delete_trigger BEFORE DELETE ON housekeeping_checkout_turnover_intakes FOR EACH ROW EXECUTE FUNCTION hk_cti_no_delete()');
        DB::statement('CREATE TRIGGER hk_cti_source_relationship_trigger BEFORE INSERT ON housekeeping_checkout_turnover_intakes FOR EACH ROW EXECUTE FUNCTION hk_cti_check_source_relationship()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS hk_cti_source_relationship_trigger ON housekeeping_checkout_turnover_intakes');
            DB::statement('DROP TRIGGER IF EXISTS hk_cti_no_update_trigger ON housekeeping_checkout_turnover_intakes');
            DB::statement('DROP TRIGGER IF EXISTS hk_cti_no_delete_trigger ON housekeeping_checkout_turnover_intakes');
            DB::statement('DROP FUNCTION IF EXISTS hk_cti_check_source_relationship()');
            DB::statement('DROP FUNCTION IF EXISTS hk_cti_no_update()');
            DB::statement('DROP FUNCTION IF EXISTS hk_cti_no_delete()');
        }

        Schema::dropIfExists('housekeeping_checkout_turnover_intakes');
    }
};
