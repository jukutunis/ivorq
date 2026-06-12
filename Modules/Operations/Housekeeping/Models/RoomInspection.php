<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class RoomInspection extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'room_inspections';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'property_id',
        'room_id',
        'cleaning_task_id',
        'supervisor_id',
        'inspection_type',
        'status',
        'inspection_severity',
        'score',
        'max_score',
        'is_passed',
        'remarks',
        'inspected_at',
        'notes',
        'results',
    ];

    protected $casts = [
        'is_passed' => 'boolean',
        'results' => 'array',
        'score' => 'integer',
        'max_score' => 'integer',
        'status' => \Modules\Operations\Housekeeping\Enums\InspectionStatusEnum::class,
        'inspection_type' => \Modules\Operations\Housekeeping\Enums\InspectionTypeEnum::class,
        'inspection_severity' => \Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum::class,
        'inspected_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function task()
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function inspector()
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'supervisor_id');
    }

    public function photos()
    {
        return $this->hasMany(InspectionPhoto::class);
    }
}