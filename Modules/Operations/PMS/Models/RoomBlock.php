<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockStatusEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class RoomBlock extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'room_id',
        'block_type',
        'status',
        'reason',
        'notes',
        'start_at',
        'end_at',
        'released_at',
        'released_by',
    ];

    protected $casts = [
        'block_type'   => RoomBlockTypeEnum::class,
        'status'       => RoomBlockStatusEnum::class,
        'reason'       => RoomBlockReasonEnum::class,
        'start_at'     => 'datetime',
        'end_at'       => 'datetime',
        'released_at'  => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
