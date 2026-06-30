<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->ulid('cashier_user_id')->index();
            $table->string('status', 20)->index();
            $table->timestamp('opened_at');
            $table->ulid('opened_by')->index();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('closed_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['property_id', 'cashier_user_id'], 'cashier_sessions_open_unique')
                ->where('status', 'OPEN');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_sessions');
    }
};
