<?php

namespace Modules\Foundation\Task\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Task\Models\Task;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::first();
        if (!$property) {
            return;
        }

        $task = Task::create([
            'property_id'   => $property->id,
            'task_type'     => 'test_task',
            'title'         => 'Foundation Test Task',
            'description'   => 'A test task created during seeding.',
            'priority'      => 'normal',
            'status'        => 'open',
            'due_date'      => now()->addDays(2),
        ]);
        
        $user = \Modules\Foundation\User\Models\User::first();
        
        if ($user) {
            $task->assignments()->create([
                'property_id' => $property->id,
                'assignee_type' => get_class($user),
                'assignee_id' => $user->id,
            ]);
        }
    }
}
