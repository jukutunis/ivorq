<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('valuation_approval_status')->nullable()->after('valuation_sequence');
            $table->string('valuation_approval_reference')->nullable()->after('valuation_approval_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE inventory_transactions
                ADD CONSTRAINT chk_inv_tx_valuation_auth_status
                CHECK (
                    valuation_approval_status IS NULL
                    OR valuation_approval_status IN ('approved', 'pending', 'rejected')
                )
                NOT VALID
            ");

            DB::statement("
                ALTER TABLE inventory_transactions
                ADD CONSTRAINT chk_inv_tx_controlled_auth_required
                CHECK (
                    idempotency_key IS NULL
                    OR (
                        valuation_approval_status = 'approved'
                        AND valuation_approval_reference IS NOT NULL
                        AND valuation_approval_reference <> ''
                    )
                )
                NOT VALID
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT IF EXISTS chk_inv_tx_controlled_auth_required');
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT IF EXISTS chk_inv_tx_valuation_auth_status');
        }

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['valuation_approval_status', 'valuation_approval_reference']);
        });
    }
};
