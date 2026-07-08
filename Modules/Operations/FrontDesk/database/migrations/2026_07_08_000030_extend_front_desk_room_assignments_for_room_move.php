<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE front_desk_room_assignments DROP CONSTRAINT IF EXISTS fdra_initial_assignment_unique');
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS fdra_initial_assignment_once_unique
            ON front_desk_room_assignments (property_id, front_desk_stay_id)
            WHERE assignment_kind = 'INITIAL_ASSIGNMENT'
        ");
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS fdra_room_move_source_hash_unique
            ON front_desk_room_assignments (property_id, front_desk_stay_id, source_hash)
            WHERE assignment_kind = 'ROOM_MOVE'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS fdra_room_move_source_hash_unique');
        DB::statement('DROP INDEX IF EXISTS fdra_initial_assignment_once_unique');
        DB::statement('ALTER TABLE front_desk_room_assignments ADD CONSTRAINT fdra_initial_assignment_unique UNIQUE (property_id, front_desk_stay_id, assignment_kind)');
    }
};
