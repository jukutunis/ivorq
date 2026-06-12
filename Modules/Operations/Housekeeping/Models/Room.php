<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

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
    ];

    public function property()
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }
}