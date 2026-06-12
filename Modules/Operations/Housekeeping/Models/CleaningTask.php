<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class CleaningTask extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'cleaning_tasks';

    protected $fillable = [
        'property_id',
        'room_id',
        'zone_id',
        'task_type',
        'status',
        'priority',
        'credits',
        'scheduled_at',
        'started_at',
        'completed_at',
        'verified_at',
        'sla_minutes_target',
        'sla_breached',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'sla_breached' => 'boolean',
        'credits' => 'decimal:2',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
    
    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }
}