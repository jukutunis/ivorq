<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_assignments')) {
            Schema::rename('task_assignments', 'housekeeping_task_assignments');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('housekeeping_task_assignments')) {
            Schema::rename('housekeeping_task_assignments', 'task_assignments');
        }
    }
};
