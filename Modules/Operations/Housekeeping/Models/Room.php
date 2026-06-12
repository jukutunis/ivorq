<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;

class Room extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'rooms';

    protected $fillable = [
        'property_id',
        'zone_id',
        'room_number',
        'room_name',
        'room_type',
        'floor',
        'building',
        'cleanliness_status',
        'readiness_state',
        'occupancy_status',
        'is_dnd',
        'turndown_required',
        'is_vip',
        'credits',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_dnd' => 'boolean',
        'turndown_required' => 'boolean',
        'is_vip' => 'boolean',
        'is_active' => 'boolean',
        'credits' => 'decimal:2',
        'cleanliness_status' => RoomCleanlinessStatusEnum::class,
        'occupancy_status' => RoomOccupancyStatusEnum::class,
        'room_type' => \Modules\Operations\Housekeeping\Enums\RoomTypeEnum::class,
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }


    public function cleaningTasks()
    {
        return $this->hasMany(CleaningTask::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(RoomStatusHistory::class);
    }

    public function inspections()
    {
        return $this->hasMany(RoomInspection::class);
    }
}