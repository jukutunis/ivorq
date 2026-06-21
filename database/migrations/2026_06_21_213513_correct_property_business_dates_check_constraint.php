<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE property_business_dates DROP CONSTRAINT chk_property_business_dates_status_open');
            DB::statement("ALTER TABLE property_business_dates ADD CONSTRAINT chk_property_business_dates_status_open CHECK (
              (
                (status = 'Open' AND is_open IS TRUE)
                OR
                (status = 'Closed' AND is_open IS NULL)
              ) IS TRUE
            )");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE property_business_dates DROP CONSTRAINT chk_property_business_dates_status_open');
            DB::statement("ALTER TABLE property_business_dates ADD CONSTRAINT chk_property_business_dates_status_open CHECK (
              (status = 'Open' AND is_open = true)
              OR
              (status = 'Closed' AND is_open IS NULL)
            )");
        }
    }
};
