<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('front_desk_checkout_executions', function (Blueprint $table) {
            $table->char('checkout_confirmation_consumption_id', 26)->nullable()->after('id');
            $table->char('checkout_confirmation_fingerprint', 64)->nullable()->after('checkout_confirmation_consumption_id');
            $table->timestamp('checkout_confirmed_at')->nullable()->after('checkout_confirmation_fingerprint');
            $table->timestamp('checkout_confirmation_expires_at')->nullable()->after('checkout_confirmed_at');
            $table->timestamp('checkout_confirmation_consumed_at')->nullable()->after('checkout_confirmation_expires_at');

            $table->unique('checkout_confirmation_consumption_id', 'fd_ce_p8_confirmation_consumption_unique');
            $table->index('checkout_confirmation_fingerprint', 'fd_ce_p8_confirmation_fingerprint_idx');
            $table->foreign('checkout_confirmation_consumption_id', 'fd_ce_p8_confirmation_consumption_fk')
                ->references('id')->on('checkout_sensitive_confirmation_consumptions')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_p8_confirmation_all_or_none
                CHECK (
                    (checkout_confirmation_consumption_id IS NULL
                     AND checkout_confirmation_fingerprint IS NULL
                     AND checkout_confirmed_at IS NULL
                     AND checkout_confirmation_expires_at IS NULL
                     AND checkout_confirmation_consumed_at IS NULL)
                    OR
                    (checkout_confirmation_consumption_id IS NOT NULL
                     AND checkout_confirmation_fingerprint IS NOT NULL
                     AND checkout_confirmed_at IS NOT NULL
                     AND checkout_confirmation_expires_at IS NOT NULL
                     AND checkout_confirmation_consumed_at IS NOT NULL)
                )
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_p8_confirmation_fingerprint_sha
                CHECK (checkout_confirmation_fingerprint IS NULL OR checkout_confirmation_fingerprint ~ '^[a-f0-9]{64}$')
            ");

            DB::statement("
                ALTER TABLE front_desk_checkout_executions
                ADD CONSTRAINT fd_ce_p8_confirmation_time_order
                CHECK (
                    checkout_confirmed_at IS NULL
                    OR (
                        checkout_confirmed_at < checkout_confirmation_expires_at
                        AND checkout_confirmed_at <= checkout_confirmation_consumed_at
                        AND checkout_confirmation_consumed_at < checkout_confirmation_expires_at
                    )
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE front_desk_checkout_executions DROP CONSTRAINT IF EXISTS fd_ce_p8_confirmation_time_order');
            DB::statement('ALTER TABLE front_desk_checkout_executions DROP CONSTRAINT IF EXISTS fd_ce_p8_confirmation_fingerprint_sha');
            DB::statement('ALTER TABLE front_desk_checkout_executions DROP CONSTRAINT IF EXISTS fd_ce_p8_confirmation_all_or_none');
        }

        Schema::table('front_desk_checkout_executions', function (Blueprint $table) {
            $table->dropForeign('fd_ce_p8_confirmation_consumption_fk');
            $table->dropUnique('fd_ce_p8_confirmation_consumption_unique');
            $table->dropIndex('fd_ce_p8_confirmation_fingerprint_idx');
            $table->dropColumn([
                'checkout_confirmation_consumption_id',
                'checkout_confirmation_fingerprint',
                'checkout_confirmed_at',
                'checkout_confirmation_expires_at',
                'checkout_confirmation_consumed_at',
            ]);
        });
    }
};
