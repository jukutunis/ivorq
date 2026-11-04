<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->unsignedInteger('revision_number')->default(1)->after('source_fingerprint');
            $table->foreignUlid('current_revision_created_by')
                ->nullable()
                ->after('revision_number')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedInteger('revision_number')->default(1)->after('purchase_request_id');
            $table->foreignUlid('current_revision_created_by')
                ->nullable()
                ->after('revision_number')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('purchase_request_revision_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_number', 50);
            $table->string('decision_status', 30);
            $table->char('revision_maker_id', 26);
            $table->char('rejected_by', 26);
            $table->char('approval_request_id', 26);
            $table->text('rejection_reason');
            $table->timestamp('rejected_at');
            $table->jsonb('commercial_header');
            $table->jsonb('commercial_lines');
            $table->char('source_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['purchase_request_id', 'revision_number'],
                'purchase_request_revision_snapshots_document_revision_unique'
            );
        });

        Schema::create('purchase_order_revision_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_number', 50);
            $table->string('decision_status', 30);
            $table->char('revision_maker_id', 26);
            $table->char('rejected_by', 26);
            $table->char('approval_request_id', 26);
            $table->text('rejection_reason');
            $table->timestamp('rejected_at');
            $table->jsonb('commercial_header');
            $table->jsonb('commercial_lines');
            $table->char('source_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['purchase_order_id', 'revision_number'],
                'purchase_order_revision_snapshots_document_revision_unique'
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION purchasing_revision_snapshots_immutable_guard() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '%: immutable append-only revision evidence', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_purchase_request_revision_snapshots_immutable
                BEFORE UPDATE OR DELETE ON purchase_request_revision_snapshots
                FOR EACH ROW EXECUTE FUNCTION purchasing_revision_snapshots_immutable_guard();

                CREATE TRIGGER trg_purchase_order_revision_snapshots_immutable
                BEFORE UPDATE OR DELETE ON purchase_order_revision_snapshots
                FOR EACH ROW EXECUTE FUNCTION purchasing_revision_snapshots_immutable_guard();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_purchase_order_revision_snapshots_immutable ON purchase_order_revision_snapshots');
            DB::statement('DROP TRIGGER IF EXISTS trg_purchase_request_revision_snapshots_immutable ON purchase_request_revision_snapshots');
            DB::statement('DROP FUNCTION IF EXISTS purchasing_revision_snapshots_immutable_guard()');
        }

        Schema::dropIfExists('purchase_order_revision_snapshots');
        Schema::dropIfExists('purchase_request_revision_snapshots');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_revision_created_by');
            $table->dropColumn('revision_number');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_revision_created_by');
            $table->dropColumn('revision_number');
        });
    }
};
