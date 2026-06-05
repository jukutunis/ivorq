<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Enums\StayStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Stay extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'reservation_id',
        'room_id',
        'guest_id',
        'status',
        'check_in_at',
        'expected_departure_at',
        'check_out_at',
    ];

    protected $casts = [
        'status'                 => StayStatusEnum::class,
        'check_in_at'            => 'datetime',
        'expected_departure_at'  => 'datetime',
        'check_out_at'           => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The actual physical room occupied during this stay.
     * Resolved at check-in; distinct from reservation.reserved_room_type.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
