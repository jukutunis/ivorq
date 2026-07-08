<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_room_readiness_transitions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->char('room_id', 26);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->string('transition_type', 30);
            $table->string('reason', 255)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->dateTime('occurred_at');
            $table->char('created_by', 26)->nullable();
            $table->string('idempotency_key', 120);
            $table->string('source_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['property_id', 'idempotency_key'], 'hk_readiness_tr_idem_unique');
            $table->unique(['property_id', 'room_id', 'source_hash'], 'hk_readiness_tr_source_hash_unique');
            $table->index(['property_id', 'room_id', 'transition_type'], 'hk_readiness_tr_room_type_idx');
            $table->index(['property_id', 'transition_type'], 'hk_readiness_tr_type_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE OR REPLACE FUNCTION hk_readiness_tr_no_update()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION \'Housekeeping room readiness transitions are immutable and cannot be updated.\';
                END;
                $$ LANGUAGE plpgsql
            ');

            DB::statement('
                CREATE OR REPLACE FUNCTION hk_readiness_tr_no_delete()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION \'Housekeeping room readiness transitions are immutable and cannot be deleted.\';
                END;
                $$ LANGUAGE plpgsql
            ');

            DB::statement('
                CREATE TRIGGER hk_readiness_transitions_no_update
                BEFORE UPDATE ON housekeeping_room_readiness_transitions
                FOR EACH ROW EXECUTE FUNCTION hk_readiness_tr_no_update()
            ');

            DB::statement('
                CREATE TRIGGER hk_readiness_transitions_no_delete
                BEFORE DELETE ON housekeeping_room_readiness_transitions
                FOR EACH ROW EXECUTE FUNCTION hk_readiness_tr_no_delete()
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS hk_readiness_transitions_no_update ON housekeeping_room_readiness_transitions');
            DB::statement('DROP TRIGGER IF EXISTS hk_readiness_transitions_no_delete ON housekeeping_room_readiness_transitions');
            DB::statement('DROP FUNCTION IF EXISTS hk_readiness_tr_no_update()');
            DB::statement('DROP FUNCTION IF EXISTS hk_readiness_tr_no_delete()');
        }

        Schema::dropIfExists('housekeeping_room_readiness_transitions');
    }
};
