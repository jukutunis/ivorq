<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class CleaningTask extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'cleaning_tasks';

    protected $attributes = [
        'status' => 'pending',
        'priority' => 'normal',
    ];

    protected $dispatchesEvents = [
        'created' => \Modules\Operations\Housekeeping\Events\CleaningTaskCreated::class,
    ];

    protected $fillable = [
        'property_id',
        'room_id',
        'zone_id',
        'task_code',
        'title',
        'task_type',
        'status',
        'priority',
        'credits',
        'scheduled_at',
        'due_date',
        'started_at',
        'completed_at',
        'completed_by',
        'verified_at',
        'sla_minutes_target',
        'sla_breached',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'due_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'sla_breached' => 'boolean',
        'credits' => 'decimal:2',
        'status' => \Modules\Operations\Housekeeping\Enums\TaskStatusEnum::class,
        'task_type' => \Modules\Operations\Housekeeping\Enums\TaskTypeEnum::class,
    ];

    public function zone()
    {
        return $this->belongsTo(\Modules\Operations\Zoning\Models\Zone::class);
    }

    public function property()
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'completed_by');
    }

    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function inspections()
    {
        return $this->hasMany(RoomInspection::class);
    }
}