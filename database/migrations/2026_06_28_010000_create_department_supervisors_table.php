<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_supervisors', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('department_id', 26);
            $table->char('user_id', 26);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            
            $table->index(['department_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });

        // PostgreSQL partial unique index to enforce active duplicate prevention
        DB::statement('CREATE UNIQUE INDEX uq_dept_user_active ON department_supervisors (department_id, user_id) WHERE (is_active = true)');
    }

    public function down(): void
    {
        Schema::dropIfExists('department_supervisors');
    }
};
