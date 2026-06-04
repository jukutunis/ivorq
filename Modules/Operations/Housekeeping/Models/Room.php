<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\Zoning\Models\Zone;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Room extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'zone_id',
        'room_number',
        'room_name',
        'room_type',
        'floor',
        'building',
        'cleanliness_status',
        'occupancy_status',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'room_type'          => RoomTypeEnum::class,
        'cleanliness_status' => RoomCleanlinessStatusEnum::class,
        'occupancy_status'   => RoomOccupancyStatusEnum::class,
        'is_active'          => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function cleaningTasks(): HasMany
    {
        return $this->hasMany(CleaningTask::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(RoomStatusHistory::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(RoomInspection::class);
    }
}
