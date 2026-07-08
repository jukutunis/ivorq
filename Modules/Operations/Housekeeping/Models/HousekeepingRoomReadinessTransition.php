<?php

namespace Modules\Operations\Housekeeping\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Enums\HousekeepingRoomReadinessTransitionTypeEnum;
use Shared\Traits\HasUlid;

class HousekeepingRoomReadinessTransition extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'housekeeping_room_readiness_transitions';

    protected $fillable = [
        'property_id',
        'room_id',
        'from_status',
        'to_status',
        'transition_type',
        'reason',
        'source_type',
        'source_id',
        'occurred_at',
        'created_by',
        'idempotency_key',
        'source_hash',
    ];

    protected $casts = [
        'transition_type' => HousekeepingRoomReadinessTransitionTypeEnum::class,
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (HousekeepingRoomReadinessTransition $transition) {
            throw new DomainException('Housekeeping room readiness transitions are immutable and cannot be updated.');
        });

        static::deleting(function (HousekeepingRoomReadinessTransition $transition) {
            throw new DomainException('Housekeeping room readiness transitions are immutable and cannot be deleted.');
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
