<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_desk_departure_checkout_final_reviews', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('property_id', 26);
            $table->string('front_desk_stay_id', 26);
            $table->string('reservation_id', 26);
            $table->string('guest_id', 26);
            $table->string('room_id', 26)->nullable();
            $table->string('final_review_status', 50);
            $table->text('final_review_note')->nullable();
            $table->timestamp('occurred_at');
            $table->string('created_by', 26);
            $table->string('idempotency_key');
            $table->string('source_hash');
            $table->timestamp('created_at');

            $table->unique(['property_id', 'idempotency_key'], 'fd_dcfr_idempotency_unique');
            $table->unique(['property_id', 'front_desk_stay_id', 'source_hash'], 'fd_dcfr_stay_source_hash_unique');
            $table->index('front_desk_stay_id', 'fd_dcfr_stay_id_idx');
            $table->index('property_id', 'fd_dcfr_property_id_idx');
            $table->index('created_at', 'fd_dcfr_created_at_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE OR REPLACE FUNCTION fd_dcfr_block_mutation() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'Front Desk departure checkout final review evidence is immutable.';
                    ELSIF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Front Desk departure checkout final review evidence is immutable.';
                    END IF; RETURN NULL;
                END; $$ LANGUAGE plpgsql;");
            DB::statement('CREATE TRIGGER fd_dcfr_block_update BEFORE UPDATE ON front_desk_departure_checkout_final_reviews FOR EACH ROW EXECUTE FUNCTION fd_dcfr_block_mutation()');
            DB::statement('CREATE TRIGGER fd_dcfr_block_delete BEFORE DELETE ON front_desk_departure_checkout_final_reviews FOR EACH ROW EXECUTE FUNCTION fd_dcfr_block_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fd_dcfr_block_update ON front_desk_departure_checkout_final_reviews');
            DB::statement('DROP TRIGGER IF EXISTS fd_dcfr_block_delete ON front_desk_departure_checkout_final_reviews');
            DB::statement('DROP FUNCTION IF EXISTS fd_dcfr_block_mutation()');
        }
        Schema::dropIfExists('front_desk_departure_checkout_final_reviews');
    }
};
