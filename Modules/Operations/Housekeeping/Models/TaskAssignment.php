<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TaskAssignment extends Model
{
    use HasUlids;

    protected $table = 'task_assignments';

    protected $fillable = [
        'cleaning_task_id',
        'attendant_id',
        'assigned_at',
        'accepted_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }
}