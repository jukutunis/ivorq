<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class TaskAssignment extends Model
{
    use HasUlids, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'housekeeping_task_assignments';

    protected $fillable = [
        'cleaning_task_id',
        'attendant_id',
        'user_id',
        'department_id',
        'status',
        'assigned_at',
        'accepted_at',
        'completed_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime', // From legacy DB fix
        'status' => \Modules\Operations\Housekeeping\Enums\AssignmentStatusEnum::class,
    ];

    public function user()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class);
    }

    public function department()
    {
        return $this->belongsTo(\Modules\Foundation\Department\Models\Department::class);
    }

    public function task()
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'assigned_by');
    }
}