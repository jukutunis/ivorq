<?php

namespace Modules\Operations\FrontDesk\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskRoomAssignmentKindEnum;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Traits\BelongsToProperty;
use Shared\Traits\HasUlid;

class FrontDeskRoomAssignment extends Model
{
    use HasUlid, BelongsToProperty;

    public const UPDATED_AT = null;

    protected $fillable = [
        'property_id',
        'front_desk_stay_id',
        'reservation_id',
        'guest_id',
        'room_id',
        'room_type_id',
        'assignment_kind',
        'assignment_reason',
        'occurred_at',
        'created_by',
        'idempotency_key',
        'source_hash',
    ];

    protected $casts = [
        'assignment_kind' => FrontDeskRoomAssignmentKindEnum::class,
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Front Desk room assignment evidence is immutable.'));
        static::deleting(fn () => throw new DomainException('Front Desk room assignment evidence is immutable.'));
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(FrontDeskStay::class, 'front_desk_stay_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
