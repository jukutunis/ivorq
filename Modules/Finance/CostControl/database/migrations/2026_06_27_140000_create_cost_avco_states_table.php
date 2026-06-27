<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_avco_states', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Scope identity — immutable after creation
            $table->char('property_id', 26);
            $table->char('location_id', 26);
            $table->char('item_id', 26);

            // Denormalized scope evidence for planner lookup; not part of unique identity
            $table->string('valuation_scope');

            // AVCO durable state — decimal(15,4) matches AvcoDecimal::SCALE = 4
            // and CostControl convention (cost_ledger_entries: quantity_delta, unit_cost, value_delta)
            $table->decimal('on_hand_quantity', 15, 4)->default(0);
            $table->decimal('carrying_value', 15, 4)->default(0);
            $table->decimal('weighted_average_unit_cost', 15, 4)->nullable();
            $table->decimal('unresolved_provisional_quantity', 15, 4)->default(0);

            // Last applied ValuationSequence — null means no sequence has been processed
            $table->unsignedBigInteger('last_valuation_sequence')->nullable();
            $table->date('last_valuation_business_date')->nullable();

            $table->timestamps();

            // One row per valuation scope — the core serialization guarantee
            $table->unique(['property_id', 'location_id', 'item_id'], 'uk_cost_avco_state_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_avco_states');
    }
};
