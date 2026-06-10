<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workflow_id', 26);
            $table->integer('sequence_no');
            $table->string('role_name', 100)->nullable();
            $table->string('permission_name', 100)->nullable();
            $table->decimal('approval_limit', 16, 2)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->boolean('is_required')->default(true);

            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workflow_id')->references('id')->on('approval_workflows')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['workflow_id', 'sequence_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
    }
};
