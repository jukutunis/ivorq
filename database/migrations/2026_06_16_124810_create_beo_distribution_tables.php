<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beo_distributions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('company_id', 26);
            $table->string('property_id', 26);
            $table->string('beo_issue_log_id', 26);
            
            $table->string('status');
            $table->string('severity');
            
            $table->timestamp('distributed_at')->nullable();
            $table->string('distributed_by', 26)->nullable();
            
            $table->timestamps();

            $table->foreign('beo_issue_log_id')->references('id')->on('beo_issue_logs')->onDelete('cascade');
        });

        // Drop the existing beo_acknowledgements table because we are changing its parent and fields
        Schema::dropIfExists('beo_acknowledgements');

        Schema::create('beo_acknowledgements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('beo_distribution_id', 26);
            $table->string('department_id', 26);
            $table->string('user_id', 26)->nullable(); // User who acknowledged
            
            $table->string('status'); // PENDING, VIEWED, ACKNOWLEDGED, REJECTED, ESCALATED
            
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            
            $table->integer('sla_hours_configured')->default(24);
            $table->timestamp('sla_breach_at')->nullable();
            
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();

            $table->foreign('beo_distribution_id')->references('id')->on('beo_distributions')->onDelete('cascade');
            $table->index('department_id');
        });

        Schema::create('beo_distribution_escalations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('beo_acknowledgement_id', 26);
            $table->integer('escalation_level')->default(1);
            $table->string('escalated_to_role_id', 26)->nullable();
            
            $table->timestamp('escalated_at')->nullable();
            
            $table->timestamps();

            $table->foreign('beo_acknowledgement_id', 'fk_beo_escalations_ack_id')
                  ->references('id')
                  ->on('beo_acknowledgements')
                  ->onDelete('cascade');
        });

        Schema::create('beo_distribution_audit_trails', function (Blueprint $table) {
            $table->ulid('id')->primary();
            
            $table->string('distribution_id', 26);
            $table->string('event_type');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->string('performed_by', 26)->nullable();
            
            $table->timestamps();

            $table->foreign('distribution_id', 'fk_beo_audit_dist_id')
                  ->references('id')
                  ->on('beo_distributions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beo_distribution_audit_trails');
        Schema::dropIfExists('beo_distribution_escalations');
        Schema::dropIfExists('beo_acknowledgements');
        Schema::dropIfExists('beo_distributions');
        
        // Recreate the old beo_acknowledgements for down method
        Schema::create('beo_acknowledgements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('beo_issue_log_id', 26);
            $table->string('department_id', 26);
            $table->string('status'); 
            $table->string('acknowledged_by', 26)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();
            $table->foreign('beo_issue_log_id')->references('id')->on('beo_issue_logs')->onDelete('cascade');
            $table->index('department_id');
        });
    }
};
