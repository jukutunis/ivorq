<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_inspections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('receiving_line_id')->constrained('receiving_lines')->cascadeOnDelete();
            
            $table->string('inspection_result');
            $table->decimal('temperature', 8, 2)->nullable();
            $table->string('visual_quality_score')->nullable();
            $table->text('notes')->nullable();
            
            $table->foreignUlid('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_inspections');
    }
};
