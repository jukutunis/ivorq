<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\InspectionSeverityEnum;
use Modules\Operations\Housekeeping\Enums\InspectionStatusEnum;
use Modules\Operations\Housekeeping\Enums\InspectionTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class RoomInspection extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'room_id',
        'cleaning_task_id',
        'inspector_id',
        'inspection_type',
        'status',
        'inspection_severity',
        'remarks',
        'inspected_at',
    ];

    protected $casts = [
        'inspection_type'     => InspectionTypeEnum::class,
        'status'              => InspectionStatusEnum::class,
        'inspection_severity' => InspectionSeverityEnum::class,
        'inspected_at'        => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class, 'inspection_id');
    }
}
