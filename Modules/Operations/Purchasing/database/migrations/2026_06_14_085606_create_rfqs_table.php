<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignUlid('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            
            $table->string('rfq_number');
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->timestamp('deadline_at')->nullable();
            $table->string('status')->default('Draft'); // Draft, Open, Closed, Awarded, Cancelled
            
            $table->timestamps();
            
            // Audit columns
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
