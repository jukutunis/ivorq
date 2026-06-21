<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_business_dates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->date('business_date');
            $table->string('status');
            $table->boolean('is_open')->nullable();
            
            $table->ulid('opened_by')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            
            $table->timestamps();
            
            $table->unique(['property_id', 'is_open'], 'idx_unique_open_business_date');
            $table->unique(['property_id', 'business_date'], 'idx_unique_property_date');
        });

        // Add CHECK constraint to enforce status/is_open integrity
        // Must reject Open+null, Closed+true, Closed+false
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            \Illuminate\Support\Facades\DB::statement("
                ALTER TABLE property_business_dates
                ADD CONSTRAINT chk_property_business_dates_status_open
                CHECK (
                    (status = 'Open' AND is_open = true)
                    OR
                    (status = 'Closed' AND is_open IS NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE property_business_dates DROP CONSTRAINT chk_property_business_dates_status_open");
        }
        Schema::dropIfExists('property_business_dates');
    }
};
