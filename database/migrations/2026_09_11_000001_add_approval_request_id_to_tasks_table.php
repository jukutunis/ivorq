<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->char('approval_request_id', 26)->nullable();
            $table->index(
                ['approval_request_id', 'status'],
                'tasks_approval_request_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_approval_request_status_index');
            $table->dropColumn('approval_request_id');
        });
    }
};
