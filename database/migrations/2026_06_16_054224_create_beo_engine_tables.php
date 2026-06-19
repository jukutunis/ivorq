<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beo_issue_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->foreignUlid('function_id')->constrained('event_functions')->onDelete('cascade');
            
            $table->string('issue_number');
            $table->integer('revision_number')->default(0);
            $table->string('status');
            
            $table->jsonb('snapshot_payload');
            $table->string('snapshot_hash');
            
            $table->ulid('previous_issue_id')->nullable();
            
            $table->timestamp('issued_at')->nullable();
            $table->string('issued_by', 26)->nullable();
            
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by', 26)->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            
            $table->timestamps();


            // We can't foreign key to self easily if we want to drop it safely without more work, 
            // but let's add it.

        });

        Schema::table('beo_issue_logs', function (Blueprint $table) {
            $table->foreign('previous_issue_id')->references('id')->on('beo_issue_logs')->nullOnDelete();
        });

        Schema::create('beo_acknowledgements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->foreignUlid('beo_issue_log_id')->constrained('beo_issue_logs')->onDelete('cascade');
            // Department relation goes to Foundation departments table, which might use string ULIDs or UUIDs.
            // Based on standard IVORQ architecture, it's string(26).
            $table->string('department_id', 26);
            
            $table->string('status'); // e.g. PENDING, ACKNOWLEDGED
            
            $table->string('acknowledged_by', 26)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            
            $table->text('notes')->nullable();
            
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            
            $table->timestamps();


            // Since we're referring to a table in another module (Foundation), it's best not to hard FK 
            // if we want strict module boundaries, but let's assume 'departments' table exists and use it,
            // or just leave it as a string index.
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beo_acknowledgements');
        Schema::dropIfExists('beo_issue_logs');
    }
};
