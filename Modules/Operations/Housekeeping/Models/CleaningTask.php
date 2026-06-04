<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class CleaningTask extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'room_id',
        'zone_id',
        'task_code',
        'title',
        'description',
        'task_type',
        'status',
        'priority',
        'estimated_duration_minutes',
        'due_date',
        'started_at',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'task_type'                  => TaskTypeEnum::class,
        'status'                     => TaskStatusEnum::class,
        'priority'                   => 'integer',
        'estimated_duration_minutes' => 'integer',
        'due_date'                   => 'datetime',
        'started_at'                 => 'datetime',
        'completed_at'               => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(RoomInspection::class);
    }
}
