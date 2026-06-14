<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_candidates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('property_id')->index();
            $table->string('source_type');
            $table->string('source_id');
            $table->string('posting_event');
            $table->string('status');
            $table->date('candidate_date');
            $table->string('description')->nullable();
            
            $table->ulid('created_by')->nullable()->index();
            $table->ulid('updated_by')->nullable()->index();
            
            $table->ulid('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'source_type', 'source_id']);
        });

        Schema::create('journal_candidate_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('journal_candidate_id')->index();
            $table->string('operational_identity');
            $table->string('entry_type');
            $table->decimal('amount', 19, 4);
            $table->ulid('cost_center_id')->nullable()->index();
            $table->string('notes')->nullable();
            
            $table->timestamps();

            $table->foreign('journal_candidate_id')
                  ->references('id')
                  ->on('journal_candidates')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_candidate_lines');
        Schema::dropIfExists('journal_candidates');
    }
};
