<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('opportunity_source')->nullable()->after('status');
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('opportunity_id', 26);
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('opportunity_id')->references('id')->on('opportunities')->onDelete('cascade');
        });

        Schema::create('proposal_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('proposal_id', 26);
            
            $table->integer('revision_number');
            $table->text('details')->nullable(); // Store immutable details of the revision
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('proposal_id')->references('id')->on('proposals')->onDelete('cascade');
            $table->unique(['proposal_id', 'revision_number'], 'proposal_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_revisions');
        Schema::dropIfExists('proposals');
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('opportunity_source');
        });
    }
};
