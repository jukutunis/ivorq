<?php

namespace Modules\Operations\Housekeeping\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Traits\HasUlid;

class HousekeepingCheckoutTurnoverIntake extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $table = 'housekeeping_checkout_turnover_intakes';

    protected $fillable = [
        'property_id',
        'front_desk_checkout_housekeeping_handoff_id',
        'checkout_execution_id',
        'front_desk_stay_id',
        'reservation_id',
        'room_id',
        'property_business_date_id',
        'business_date',
        'cleaning_task_id',
        'room_readiness_transition_id',
        'handoff_source_hash',
        'checkout_execution_source_hash',
        'source_hash',
        'room_readiness_before',
        'room_readiness_after',
        'cleanliness_before',
        'cleanliness_after',
        'consumer_identity',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(
            fn () => throw new DomainException('HK_P11_CHECKOUT_TURNOVER_INTAKE_IMMUTABLE')
        );
        static::deleting(
            fn () => throw new DomainException('HK_P11_CHECKOUT_TURNOVER_INTAKE_DELETE_FORBIDDEN')
        );
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function handoff(): BelongsTo
    {
        return $this->belongsTo(FrontDeskCheckoutHousekeepingHandoff::class, 'front_desk_checkout_housekeeping_handoff_id');
    }

    public function checkoutExecution(): BelongsTo
    {
        return $this->belongsTo(FrontDeskCheckoutExecution::class, 'checkout_execution_id');
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(FrontDeskStay::class, 'front_desk_stay_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function propertyBusinessDate(): BelongsTo
    {
        return $this->belongsTo(PropertyBusinessDate::class, 'property_business_date_id');
    }

    public function cleaningTask(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class);
    }

    public function readinessTransition(): BelongsTo
    {
        return $this->belongsTo(HousekeepingRoomReadinessTransition::class, 'room_readiness_transition_id');
    }
}
