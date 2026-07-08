<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Enums\EngineeringRoomAvailabilityBlockStatusEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class EngineeringRoomAvailabilityBlock extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty;

    protected $fillable = [
        'property_id',
        'room_id',
        'block_status',
        'block_reason',
        'source_type',
        'source_id',
        'started_at',
        'started_by',
        'released_at',
        'released_by',
        'release_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'block_status' => EngineeringRoomAvailabilityBlockStatusEnum::class,
        'started_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
