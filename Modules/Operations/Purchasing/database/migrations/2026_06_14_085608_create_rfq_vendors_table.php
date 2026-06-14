<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->constrained('vendors')->cascadeOnDelete();
            
            $table->string('status')->default('Invited'); // Invited, Responded, Declined
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_vendors');
    }
};
