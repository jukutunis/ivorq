<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_discrepancies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('receiving_line_id')->constrained('receiving_lines')->cascadeOnDelete();
            
            $table->string('discrepancy_type');
            $table->decimal('reported_quantity', 15, 2);
            $table->text('reason')->nullable();
            
            $table->string('status')->default('pending');
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_discrepancies');
    }
};
