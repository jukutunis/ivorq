<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_desk_departure_operational_handovers', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('property_id', 26);
            $table->string('front_desk_stay_id', 26);
            $table->string('reservation_id', 26);
            $table->string('guest_id', 26);
            $table->string('room_id', 26)->nullable();
            $table->string('handover_status', 50);
            $table->text('handover_note')->nullable();
            $table->timestamp('occurred_at');
            $table->string('created_by', 26);
            $table->string('idempotency_key');
            $table->string('source_hash');
            $table->timestamp('created_at');

            $table->unique(['property_id', 'idempotency_key'],
                'fd_doh_idempotency_unique');
            $table->unique(['property_id', 'front_desk_stay_id', 'source_hash'],
                'fd_doh_stay_source_hash_unique');

            $table->index('front_desk_stay_id', 'fd_doh_stay_id_idx');
            $table->index('property_id', 'fd_doh_property_id_idx');
            $table->index('created_at', 'fd_doh_created_at_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE OR REPLACE FUNCTION fd_doh_block_mutation()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION 'Front Desk departure operational handover evidence is immutable.';
                    ELSIF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Front Desk departure operational handover evidence is immutable.';
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql;
            ");

            DB::statement('
                CREATE TRIGGER fd_doh_block_update
                BEFORE UPDATE ON front_desk_departure_operational_handovers
                FOR EACH ROW EXECUTE FUNCTION fd_doh_block_mutation()
            ');

            DB::statement('
                CREATE TRIGGER fd_doh_block_delete
                BEFORE DELETE ON front_desk_departure_operational_handovers
                FOR EACH ROW EXECUTE FUNCTION fd_doh_block_mutation()
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fd_doh_block_update ON front_desk_departure_operational_handovers');
            DB::statement('DROP TRIGGER IF EXISTS fd_doh_block_delete ON front_desk_departure_operational_handovers');
            DB::statement('DROP FUNCTION IF EXISTS fd_doh_block_mutation()');
        }

        Schema::dropIfExists('front_desk_departure_operational_handovers');
    }
};
