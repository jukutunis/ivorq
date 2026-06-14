<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('stock_count_session_id')->index();
            $table->ulid('item_id')->index();

            $table->decimal('expected_quantity_snapshot', 15, 4)->nullable();
            $table->timestamp('snapshot_timestamp')->nullable();

            $table->decimal('counted_quantity', 15, 4)->nullable();
            $table->decimal('variance_quantity', 15, 4)->nullable();
            $table->string('reason_code')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
    }
};
