<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('proposal_number');
            $table->string('currency_code', 3);
            $table->string('status');
            $table->string('source_fingerprint', 64);
            $table->decimal('total_amount', 19, 2);
            $table->ulid('cancelled_by')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['property_id', 'proposal_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX payment_proposals_draft_identity
                ON payment_proposals (property_id, vendor_id, currency_code, source_fingerprint)
                WHERE status = 'DRAFT'
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payment_proposals_draft_identity');
        }

        Schema::dropIfExists('payment_proposals');
    }
};
