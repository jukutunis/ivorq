<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_transfer_pair_resolutions', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            // Immutable pair identity — pair key is (property_id, source_document_id, source_line_id)
            $table->char('property_id', 26)->comment('Immutable');
            $table->char('source_document_id', 26)->comment('Immutable');
            $table->char('source_line_id', 26)->comment('Immutable');

            // Immutable InventoryTransaction references — no FK to avoid Inventory dependency
            $table->char('source_inventory_transaction_id', 26)->comment('Immutable');
            $table->char('destination_inventory_transaction_id', 26)->comment('Immutable');

            // Immutable AVCO scope and sequence evidence
            $table->string('source_valuation_scope')->comment('Immutable');
            $table->string('destination_valuation_scope')->comment('Immutable');
            $table->unsignedBigInteger('source_valuation_sequence')->comment('Immutable');
            $table->unsignedBigInteger('destination_valuation_sequence')->comment('Immutable');

            // Mutable lifecycle fields
            $table->decimal('frozen_source_unit_cost', 15, 4)->nullable();
            $table->string('lifecycle_status', 20)->default('pending');
            $table->string('blocking_reason_code', 120)->nullable();

            $table->timestamps();
        });

        // Canonical pair key — one row per transfer line pair
        DB::statement('ALTER TABLE cost_transfer_pair_resolutions ADD CONSTRAINT uk_ctpr_pair_key UNIQUE (property_id, source_document_id, source_line_id)');

        DB::statement('CREATE INDEX idx_ctpr_property_status ON cost_transfer_pair_resolutions (property_id, lifecycle_status)');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE cost_transfer_pair_resolutions
                ADD CONSTRAINT chk_ctpr_lifecycle_status
                CHECK (lifecycle_status IN ('pending', 'frozen', 'applied', 'delivered'))
            ");

            DB::statement("
                ALTER TABLE cost_transfer_pair_resolutions
                ADD CONSTRAINT chk_ctpr_frozen_cost_non_negative
                CHECK (frozen_source_unit_cost IS NULL OR frozen_source_unit_cost >= 0)
            ");

            // Frozen cost is required once lifecycle advances beyond pending
            DB::statement("
                ALTER TABLE cost_transfer_pair_resolutions
                ADD CONSTRAINT chk_ctpr_frozen_cost_required_when_advanced
                CHECK (lifecycle_status = 'pending' OR frozen_source_unit_cost IS NOT NULL)
            ");

            DB::statement("
                CREATE OR REPLACE FUNCTION guard_ctpr_immutability()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    IF NEW.property_id IS DISTINCT FROM OLD.property_id THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: property_id is immutable';
                    END IF;
                    IF NEW.source_document_id IS DISTINCT FROM OLD.source_document_id THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: source_document_id is immutable';
                    END IF;
                    IF NEW.source_line_id IS DISTINCT FROM OLD.source_line_id THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: source_line_id is immutable';
                    END IF;
                    IF NEW.source_inventory_transaction_id IS DISTINCT FROM OLD.source_inventory_transaction_id THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: source_inventory_transaction_id is immutable';
                    END IF;
                    IF NEW.destination_inventory_transaction_id IS DISTINCT FROM OLD.destination_inventory_transaction_id THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: destination_inventory_transaction_id is immutable';
                    END IF;
                    IF NEW.source_valuation_scope IS DISTINCT FROM OLD.source_valuation_scope THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: source_valuation_scope is immutable';
                    END IF;
                    IF NEW.destination_valuation_scope IS DISTINCT FROM OLD.destination_valuation_scope THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: destination_valuation_scope is immutable';
                    END IF;
                    IF NEW.source_valuation_sequence IS DISTINCT FROM OLD.source_valuation_sequence THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: source_valuation_sequence is immutable';
                    END IF;
                    IF NEW.destination_valuation_sequence IS DISTINCT FROM OLD.destination_valuation_sequence THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: destination_valuation_sequence is immutable';
                    END IF;
                    IF OLD.frozen_source_unit_cost IS NOT NULL
                       AND NEW.frozen_source_unit_cost IS DISTINCT FROM OLD.frozen_source_unit_cost THEN
                        RAISE EXCEPTION 'cost_transfer_pair_resolutions: frozen_source_unit_cost is immutable after initial set';
                    END IF;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
            ");

            DB::statement("
                CREATE TRIGGER trg_ctpr_guard_immutability
                BEFORE UPDATE ON cost_transfer_pair_resolutions
                FOR EACH ROW EXECUTE FUNCTION guard_ctpr_immutability();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_ctpr_guard_immutability ON cost_transfer_pair_resolutions');
            DB::statement('DROP FUNCTION IF EXISTS guard_ctpr_immutability()');
        }

        Schema::dropIfExists('cost_transfer_pair_resolutions');
    }
};
