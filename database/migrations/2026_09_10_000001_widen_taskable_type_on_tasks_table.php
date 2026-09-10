<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('taskable_type', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasIdentityTooLongForRollback = DB::table('tasks')
            ->whereNotNull('taskable_type')
            ->whereRaw('LENGTH(taskable_type) > ?', [50])
            ->exists();

        if ($hasIdentityTooLongForRollback) {
            throw new RuntimeException(
                'Cannot restore tasks.taskable_type to varchar(50): stored polymorphic identity exceeds 50 characters.'
            );
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('taskable_type', 50)->nullable()->change();
        });
    }
};
