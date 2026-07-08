<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_desk_stays', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->string('status', 40);
            $table->char('current_room_id', 26)->nullable();
            $table->char('current_room_assignment_id', 26)->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->char('checked_in_by', 26)->nullable();
            $table->char('created_by', 26);
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->restrictOnDelete();
            $table->foreign('current_room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('checked_in_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'reservation_id'], 'fds_property_reservation_unique');
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'current_room_id', 'status'], 'fds_room_status_index');
        });

        Schema::create('front_desk_room_assignments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('front_desk_stay_id', 26);
            $table->char('reservation_id', 26);
            $table->char('guest_id', 26);
            $table->char('room_id', 26);
            $table->char('room_type_id', 26)->nullable();
            $table->string('assignment_kind', 40);
            $table->text('assignment_reason')->nullable();
            $table->dateTime('occurred_at');
            $table->char('created_by', 26);
            $table->timestamp('created_at')->nullable();
            $table->string('idempotency_key', 120);
            $table->string('source_hash', 64);

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('front_desk_stay_id')->references('id')->on('front_desk_stays')->restrictOnDelete();
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
            $table->foreign('guest_id')->references('id')->on('guests')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unique(['property_id', 'idempotency_key'], 'fdra_property_idempotency_unique');
            $table->unique(['property_id', 'front_desk_stay_id', 'assignment_kind'], 'fdra_initial_assignment_unique');
            $table->index(['property_id', 'room_id']);
            $table->index(['property_id', 'reservation_id']);
        });

        Schema::table('front_desk_stays', function (Blueprint $table) {
            $table->foreign('current_room_assignment_id', 'fds_current_assignment_fk')
                ->references('id')
                ->on('front_desk_room_assignments')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX front_desk_stays_active_room_unique
                ON front_desk_stays (property_id, current_room_id)
                WHERE current_room_id IS NOT NULL
                  AND status IN ('ROOM_ASSIGNED', 'CHECK_IN_CONFIRMATION_PENDING', 'IN_HOUSE')
            ");

            DB::statement("
                CREATE OR REPLACE FUNCTION prevent_front_desk_room_assignment_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Front Desk room assignment evidence is immutable';
                END;
                $$ LANGUAGE plpgsql
            ");

            DB::statement("
                CREATE TRIGGER front_desk_room_assignments_no_update
                BEFORE UPDATE ON front_desk_room_assignments
                FOR EACH ROW EXECUTE FUNCTION prevent_front_desk_room_assignment_mutation()
            ");

            DB::statement("
                CREATE TRIGGER front_desk_room_assignments_no_delete
                BEFORE DELETE ON front_desk_room_assignments
                FOR EACH ROW EXECUTE FUNCTION prevent_front_desk_room_assignment_mutation()
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS front_desk_room_assignments_no_update ON front_desk_room_assignments');
            DB::statement('DROP TRIGGER IF EXISTS front_desk_room_assignments_no_delete ON front_desk_room_assignments');
            DB::statement('DROP FUNCTION IF EXISTS prevent_front_desk_room_assignment_mutation');
            DB::statement('DROP INDEX IF EXISTS front_desk_stays_active_room_unique');
        }

        Schema::table('front_desk_stays', function (Blueprint $table) {
            $table->dropForeign('fds_current_assignment_fk');
        });

        Schema::dropIfExists('front_desk_room_assignments');
        Schema::dropIfExists('front_desk_stays');
    }
};
