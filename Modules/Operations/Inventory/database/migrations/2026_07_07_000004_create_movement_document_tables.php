<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_inventory_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->string('transfer_number');
            $table->ulid('from_location_id');
            $table->ulid('to_location_id');
            $table->string('status');
            $table->timestamp('posted_at')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index('property_id');
        });

        Schema::create('controlled_inventory_transfer_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('transfer_id');
            $table->ulid('property_id');
            $table->ulid('inventory_item_id');
            $table->ulid('from_location_id');
            $table->ulid('to_location_id');
            $table->ulid('inventory_unit_id');
            $table->decimal('quantity', 12, 3);
            $table->string('idempotency_key');
            $table->ulid('stock_movement_out_id')->nullable();
            $table->ulid('stock_movement_in_id')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['property_id', 'idempotency_key'], 'uq_transfer_lines_idempotency');
            $table->index('transfer_id');
        });

        Schema::create('controlled_inventory_issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->string('issue_number');
            $table->ulid('inventory_location_id');
            $table->string('reason_code');
            $table->string('status');
            $table->timestamp('posted_at')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index('property_id');
        });

        Schema::create('controlled_inventory_issue_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('issue_id');
            $table->ulid('property_id');
            $table->ulid('inventory_item_id');
            $table->ulid('inventory_location_id');
            $table->ulid('inventory_unit_id');
            $table->decimal('quantity', 12, 3);
            $table->string('idempotency_key');
            $table->ulid('stock_movement_id')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['property_id', 'idempotency_key'], 'uq_issue_lines_idempotency');
            $table->index('issue_id');
        });

        Schema::create('controlled_inventory_stock_counts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->string('count_number');
            $table->ulid('inventory_location_id');
            $table->string('status');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->ulid('requested_by');
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index('property_id');
        });

        Schema::create('controlled_inventory_stock_count_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('stock_count_id');
            $table->ulid('property_id');
            $table->ulid('inventory_item_id');
            $table->decimal('expected_quantity', 12, 3);
            $table->decimal('counted_quantity', 12, 3);
            $table->string('idempotency_key');
            $table->ulid('stock_movement_id')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['property_id', 'idempotency_key'], 'uq_count_lines_idempotency');
            $table->index('stock_count_id');
        });

        Schema::create('controlled_inventory_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id');
            $table->string('adjustment_number');
            $table->ulid('inventory_location_id');
            $table->string('reason_code');
            $table->string('status');
            $table->timestamp('posted_at')->nullable();
            $table->ulid('requested_by');
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index('property_id');
        });

        Schema::create('controlled_inventory_adjustment_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('adjustment_id');
            $table->ulid('property_id');
            $table->ulid('inventory_item_id');
            $table->ulid('inventory_location_id');
            $table->ulid('inventory_unit_id');
            $table->decimal('quantity', 12, 3);
            $table->string('direction');
            $table->string('idempotency_key');
            $table->ulid('stock_movement_id')->nullable();
            $table->ulid('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['property_id', 'idempotency_key'], 'uq_adjustment_lines_idempotency');
            $table->index('adjustment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_lines');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('inventory_stock_count_lines');
        Schema::dropIfExists('inventory_stock_counts');
        Schema::dropIfExists('inventory_issue_lines');
        Schema::dropIfExists('inventory_issues');
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
    }
};
