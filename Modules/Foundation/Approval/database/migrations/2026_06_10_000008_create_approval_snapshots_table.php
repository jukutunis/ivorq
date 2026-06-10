<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_snapshots', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('reference_type', 100);
            $table->char('reference_id', 26);
            $table->char('workflow_id', 26)->nullable();
            $table->integer('sequence_no')->nullable();
            
            $table->char('approver_id', 26);
            $table->string('approver_name', 100);
            $table->string('role_name', 100)->nullable();
            $table->decimal('approval_limit', 16, 2)->nullable();
            
            $table->string('action', 30);
            $table->text('remarks')->nullable();
            $table->timestamp('approved_at')->useCurrent();

            $table->foreign('workflow_id')->references('id')->on('approval_workflows')->nullOnDelete();
            $table->foreign('approver_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_snapshots');
    }
};
