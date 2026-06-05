<?php

namespace Modules\Operations\PMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class Reservation extends Model
{
    use HasUlid, HasAuditColumns, BelongsToProperty, SoftDeletes;

    protected $fillable = [
        'property_id',
        'reservation_number',
        'primary_guest_id',
        'rate_plan_id',
        'adults',
        'children',
        'arrival_date',
        'departure_date',
        'nights',
        'reservation_source',
        'status',
        'reserved_room_type',
        'assigned_room_id',
        'remarks',
    ];

    protected $casts = [
        'reservation_source' => ReservationSourceEnum::class,
        'status'             => ReservationStatusEnum::class,
        // reserved_room_type is a string column but maps to the Housekeeping RoomTypeEnum
        'reserved_room_type' => RoomTypeEnum::class,
        'arrival_date'       => 'date',
        'departure_date'     => 'date',
        'adults'             => 'integer',
        'children'           => 'integer',
        'nights'             => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'primary_guest_id');
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * The specific room physically assigned to this reservation (nullable until check-in).
     */
    public function assignedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'assigned_room_id');
    }

    /**
     * All guests linked to this reservation (includes primary and additional guests).
     */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guests')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function activeStay(): HasOne
    {
        return $this->hasOne(Stay::class)->whereIn('status', ['reserved', 'checked_in']);
    }

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function roomBlocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }
}
